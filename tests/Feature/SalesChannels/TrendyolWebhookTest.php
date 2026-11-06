<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolWebhookManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/** Order-package webhooks as Trendyol documents them: at-least-once, possibly out of order, authenticated by an API key header. */
class TrendyolWebhookTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => Trendyol::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789', 'webhook_api_key' => 'webhook-key-555'],
            adminId: 1,
        );
        $this->account->forceFill(['enabled' => true, 'capabilities' => ['connection_test', 'order_import', 'read_only_sync']])->save();
    }

    private function url(?int $account = null): string
    {
        return '/api/sales-channel-webhooks/' . ($account ?? $this->account->id);
    }

    /** @return array<string, string> */
    private function key(string $key = 'webhook-key-555'): array
    {
        return ['x-api-key' => $key];
    }

    public function test_an_unknown_account_a_wrong_key_and_a_missing_key_are_the_same_flat_401(): void
    {
        $this->postJson($this->url(9999), Trendyol::package(), $this->key())->assertStatus(401)->assertExactJson(['success' => false, 'message' => 'Unauthorized request']);
        $this->postJson($this->url(), Trendyol::package(), $this->key('wrong'))->assertStatus(401);
        $this->postJson($this->url(), Trendyol::package())->assertStatus(401);

        $this->assertSame(0, ExternalOrderMapping::count());
        $this->assertSame(0, SalesChannelEvent::count());
    }

    public function test_an_account_with_no_registered_key_accepts_nothing(): void
    {
        app(ChannelCredentialStore::class)->save($this->account, [], clear: ['webhook_api_key']);

        $this->postJson($this->url(), Trendyol::package(), $this->key(''))->assertStatus(401);
        $this->postJson($this->url(), Trendyol::package(), $this->key('webhook-key-555'))->assertStatus(401);
    }

    public function test_another_channels_account_cannot_be_reached_through_the_trendyol_address(): void
    {
        $amazon = app(ChannelAccountService::class)->create(['channel' => 'amazon', 'name' => 'Amazon', 'external_account_id' => 'A1', 'currency' => 'SAR', 'settings' => ['region' => 'eu']], ['lwa_client_id' => 'a', 'lwa_client_secret' => 'b', 'refresh_token' => 'c'], adminId: 1);

        $this->postJson($this->url($amazon->id), Trendyol::package(), $this->key())->assertStatus(401);
    }

    public function test_a_bare_package_is_recorded_once_with_nothing_personal(): void
    {
        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk()->assertJsonPath('counts.applied', 1);

        $mapping = ExternalOrderMapping::firstOrFail();
        $this->assertSame(['10234567890', '3012345678', ChannelOrderStatus::New], [$mapping->external_order_id, $mapping->external_package_id, $mapping->orderStatus()]);
        $this->assertNull($mapping->order_id, 'A webhook creates no internal order.');
        $stored = json_encode([SalesChannelEvent::all()->toArray(), $mapping->snapshot, $mapping->lines->toArray()]);
        foreach (['Buyer', 'buyer-token', '1 Test Street', '+966500000000', 'RRRD1234'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
    }

    public function test_the_wrapped_form_the_documentation_shows_is_accepted_too(): void
    {
        $wrapped = ['totalElements' => 2, 'totalPages' => 1, 'page' => 0, 'size' => 50, 'content' => [Trendyol::package(), Trendyol::package(['shipmentPackageId' => 3012345679, 'orderNumber' => '10234567891'])]];

        $this->postJson($this->url(), $wrapped, $this->key())->assertOk()->assertJsonPath('counts.applied', 2);

        $this->assertSame(2, ExternalOrderMapping::count());
    }

    public function test_a_delivery_repeated_by_trendyol_changes_nothing(): void
    {
        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk()->assertJsonPath('counts.applied', 1);
        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk()->assertJsonPath('counts.duplicate', 1);

        $this->assertSame(1, ExternalOrderMapping::count());
        $this->assertSame(1, SalesChannelEvent::count());
    }

    public function test_an_old_event_arriving_after_a_newer_one_does_not_move_the_order_backwards(): void
    {
        $shipped = Trendyol::package(['status' => 'Shipped', 'shipmentPackageStatus' => 'Shipped', 'lastModifiedDate' => Trendyol::CREATED_MS + 3_600_000]);
        $this->postJson($this->url(), $shipped, $this->key())->assertOk();

        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk();

        $this->assertSame(ChannelOrderStatus::Shipped, ExternalOrderMapping::firstOrFail()->orderStatus());
        $this->assertSame(2, SalesChannelEvent::count(), 'Both deliveries are kept as events.');
    }

    public function test_a_cancellation_is_applied(): void
    {
        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk();
        $cancelled = Trendyol::package(['status' => 'Cancelled', 'shipmentPackageStatus' => 'Cancelled', 'lastModifiedDate' => Trendyol::CREATED_MS + 60_000]);

        $this->postJson($this->url(), $cancelled, $this->key())->assertOk();

        $this->assertSame(ChannelOrderStatus::Cancelled, ExternalOrderMapping::firstOrFail()->orderStatus());
    }

    public function test_nothing_is_applied_while_order_import_is_off_but_the_gap_is_recorded(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test']])->save();

        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk()->assertJsonPath('counts.ignored_capability_off', 1);

        $this->assertSame(0, ExternalOrderMapping::count());
        $this->assertSame('ignored_capability_off', SalesChannelEvent::firstOrFail()->outcome);
    }

    public function test_a_global_switch_that_is_off_applies_nothing(): void
    {
        config(['sales_channels.enabled' => false]);

        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertOk()->assertJsonPath('counts.ignored_capability_off', 1);

        $this->assertSame(0, ExternalOrderMapping::count());
    }

    public function test_a_body_that_is_not_a_package_is_counted_and_an_empty_one_is_refused(): void
    {
        $this->postJson($this->url(), ['hello' => 'world'], $this->key())->assertOk()->assertJsonPath('counts.ignored_invalid', 1);
        $this->postJson($this->url(), [], $this->key())->assertStatus(422);

        $this->assertSame(0, SalesChannelEvent::count());
    }

    public function test_an_oversized_body_is_refused_before_it_is_read(): void
    {
        config(['sales_channels.trendyol.webhook_max_bytes' => 100]);

        $this->postJson($this->url(), Trendyol::package(), $this->key())->assertStatus(413);
    }

    // ---- registering the webhook ------------------------------------------------------------------------

    private function approve(): void
    {
        $this->account->forceFill(['write_enabled' => true, 'dry_run' => false, 'capabilities' => ['connection_test', 'order_import'], 'capability_approvals' => ['webhook_management' => ['by' => 1]]])->save();
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    public function test_registering_sends_the_api_key_authentication_and_returns_the_id(): void
    {
        $this->approve();
        Http::fake(['https://apigw.trendyol.com/*' => Http::response(['id' => 'hook-uuid-1'])]);

        $id = app(TrendyolWebhookManager::class)->register($this->account, 'https://shop.example.test/api/sales-channel-webhooks/' . $this->account->id, ['CREATED', 'CANCELLED'], ['SA']);

        $this->assertSame('hook-uuid-1', $id);
        $request = $this->sentTo('/integration/webhook/sellers/' . Trendyol::SELLER . '/webhooks')[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(['url' => 'https://shop.example.test/api/sales-channel-webhooks/' . $this->account->id, 'authenticationType' => 'API_KEY', 'apiKey' => 'webhook-key-555', 'subscribedStatuses' => ['CREATED', 'CANCELLED'], 'countryCodes' => ['SA']], $request->data());
    }

    public function test_the_key_is_created_once_stored_encrypted_and_never_logged(): void
    {
        $this->approve();
        app(ChannelCredentialStore::class)->save($this->account, [], clear: ['webhook_api_key']);
        Http::fake(['https://apigw.trendyol.com/*' => Http::response(['id' => 'hook-uuid-1'])]);
        $manager = app(TrendyolWebhookManager::class);

        $manager->register($this->account->fresh(), 'https://shop.example.test/hook');
        $manager->register($this->account->fresh(), 'https://shop.example.test/hook');

        $keys = array_map(fn (Request $request): string => $request->data()['apiKey'], $this->sentTo('/webhooks'));
        $this->assertSame(48, strlen($keys[0]));
        $this->assertSame($keys[0], $keys[1], 'The same key is reused.');
        $this->assertTrue(app(ChannelCredentialStore::class)->get($this->account->fresh())->has('webhook_api_key'));
        $this->assertStringNotContainsString($keys[0], json_encode(\App\Models\SalesChannelApiLog::all()->toArray()));
    }

    public function test_an_address_trendyol_would_refuse_is_refused_here_first(): void
    {
        $this->approve();
        Http::fake();
        $manager = app(TrendyolWebhookManager::class);

        foreach (['http://shop.example.test/hook', 'https://trendyol-hooks.example.test/x', 'https://localhost/hook', 'https://dolap.example.test/hook'] as $url) {
            try {
                $manager->register($this->account, $url);
                $this->fail("{$url} was sent.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_registering_needs_the_webhook_approval_and_a_dry_run_sends_nothing(): void
    {
        $this->account->forceFill(['write_enabled' => true, 'dry_run' => false, 'capabilities' => ['order_import'], 'capability_approvals' => []])->save();
        Http::fake();
        try {
            app(TrendyolWebhookManager::class)->register($this->account->fresh(), 'https://shop.example.test/hook');
            $this->fail('A webhook was registered without approval.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::NOT_APPROVED, $exception->reason);
        }

        $this->account->forceFill(['dry_run' => true])->save();
        $this->assertNull(app(TrendyolWebhookManager::class)->register($this->account->fresh(), 'https://shop.example.test/hook'));
        Http::assertNothingSent();
    }

    public function test_the_webhooks_can_be_listed_switched_and_removed(): void
    {
        $this->approve();
        Http::fake(['https://apigw.trendyol.com/*' => Http::response([['id' => 'hook-1', 'status' => 'ACTIVE', 'url' => 'https://shop.example.test/hook', 'subscribedStatuses' => null]])]);
        $manager = app(TrendyolWebhookManager::class);

        $this->assertSame('hook-1', $manager->list($this->account)[0]['id']);
        $this->assertTrue($manager->deactivate($this->account, 'hook-1'));
        $this->assertTrue($manager->activate($this->account, 'hook-1'));
        $this->assertTrue($manager->delete($this->account, 'hook-1'));

        $this->assertSame(['GET', 'PUT', 'PUT', 'DELETE'], array_map(fn (Request $request): string => $request->method(), $this->sentTo('/webhooks')));
        $this->assertStringEndsWith('/hook-1/deactivate', $this->sentTo('/webhooks')[1]->url());
    }
}
