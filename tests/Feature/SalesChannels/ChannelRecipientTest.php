<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\SalesChannels\Amazon\AmazonConnector;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/** Fixtures shaped after the channels' published models: they prove what is asked for and what is refused, not that a live account hands the data over. */
class ChannelRecipientTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const TY = 'https://apigw.trendyol.com/integration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true, 'sales_channels.buyer_data_transfer_enabled' => true]);
    }

    private function approved(SalesChannelAccount $account): SalesChannelAccount
    {
        $approvals = (array) $account->capability_approvals;
        $approvals['buyer_data_transfer'] = ['by' => 1, 'at' => now()->toIso8601String(), 'reason' => 'owner approved'];

        return tap($account)->forceFill(['capability_approvals' => $approvals])->save() ? $account->fresh() : $account;
    }

    private function mapping(SalesChannelAccount $account, string $orderId, string $package = ''): ExternalOrderMapping
    {
        return ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => $account->channel, 'external_order_id' => $orderId, 'external_package_id' => $package,
            'status' => 'new', 'fulfillment_mode' => 'fbm', 'order_total' => 230, 'currency' => 'SAR',
        ]);
    }

    private function amazonOrder(array $recipient = [], string $fulfilledBy = 'MERCHANT'): array
    {
        $order = Amazon::order('171-0000001-0000001', $fulfilledBy);
        $order['recipient']['deliveryAddress'] = $recipient + $order['recipient']['deliveryAddress'];

        return $order;
    }

    /** A clean fake: a later Http::fake() adds stubs behind the earlier ones, which is not what a second answer in one test means. */
    private function fakeOnly(array $stubs): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function fakeAmazon(array $order, int $status = 200): void
    {
        $this->fakeOnly([
            self::LWA => Http::response(['access_token' => 'Atza|access-token-123', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'Atzr|refresh-token-abc']),
            self::SP . '/orders/*' => Http::response($status === 200 ? ['order' => $order] : ['errors' => [['code' => 'NotFound']]], $status),
        ]);
    }

    private function amazonRecipient(array $recipient = [], string $fulfilledBy = 'MERCHANT'): ChannelRecipient
    {
        $account = $this->approved($this->amazonAccount());
        $this->fakeAmazon($this->amazonOrder($recipient, $fulfilledBy));

        return app(AmazonConnector::class)->recipient($account, $this->mapping($account, '171-0000001-0000001'));
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    private function assertRefusedBecause(string $reason, callable $call, array $problems = []): void
    {
        try {
            $call();
            $this->fail("Expected the recipient to be refused with '{$reason}'.");
        } catch (RecipientUnavailable $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($problems, $exception->problems);
            $this->assertStringNotContainsString('Test Buyer', $exception->getMessage());
        }
    }

    public function test_amazon_gives_the_four_things_a_provider_needs_and_asks_for_nothing_else(): void
    {
        $recipient = $this->amazonRecipient();

        $this->assertSame([], $recipient->problems());
        $this->assertSame(['contact_person_name' => 'Test Buyer', 'phone' => '0500000000', 'address' => '1 Test Street', 'city' => 'Riyadh', 'state' => null, 'country' => 'SA', 'email' => '', 'zip' => null], $recipient->shippingAddressData());
        $request = $this->sentTo('/orders/2026-01-01/orders/171-0000001-0000001')[0];
        $this->assertSame('RECIPIENT,FULFILLMENT', $request->data()['includedData'] ?? $this->queryOf($request)['includedData'] ?? null);
        $this->assertStringNotContainsString('BUYER', $request->url());
    }

    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_amazon_without_the_role_leaves_name_phone_and_street_out_and_that_is_an_incomplete_recipient_not_a_guess(): void
    {
        $account = $this->approved($this->amazonAccount());
        $order = Amazon::order();
        $order['recipient']['deliveryAddress'] = ['city' => 'Riyadh', 'postalCode' => '11564', 'countryCode' => 'SA'];
        $this->fakeAmazon($order);

        $this->assertRefusedBecause(RecipientUnavailable::INCOMPLETE, fn () => app(AmazonConnector::class)->recipient($account, $this->mapping($account, '171-0000001-0000001')), ['name_missing', 'phone_missing', 'address_missing']);
    }

    public function test_an_order_amazon_ships_itself_is_never_ours_to_ship(): void
    {
        $account = $this->approved($this->amazonAccount());
        $this->fakeAmazon($this->amazonOrder([], 'AMAZON'));

        $this->assertRefusedBecause(RecipientUnavailable::NOT_SHIPPABLE_BY_US, fn () => app(AmazonConnector::class)->recipient($account, $this->mapping($account, '171-0000001-0000001')));
    }

    public function test_amazon_answers_that_are_not_an_address_are_named_without_saying_anything_about_the_buyer(): void
    {
        $account = $this->approved($this->amazonAccount());
        $mapping = $this->mapping($account, '171-0000001-0000001');

        $this->fakeAmazon([], 404);
        $this->assertRefusedBecause(RecipientUnavailable::NOT_FOUND, fn () => app(AmazonConnector::class)->recipient($account, $mapping));

        $this->fakeOnly([self::LWA => Http::response(['access_token' => 'Atza|x', 'token_type' => 'bearer', 'expires_in' => 3600]), self::SP . '/orders/*' => Http::response(['errors' => [['code' => 'Unauthorized']]], 403)]);
        $this->assertRefusedBecause(RecipientUnavailable::NOT_PERMITTED, fn () => app(AmazonConnector::class)->recipient($account, $mapping));
    }

    public function test_a_phone_that_is_not_a_saudi_mobile_is_reported_by_code_only(): void
    {
        $account = $this->approved($this->amazonAccount());
        $this->fakeAmazon($this->amazonOrder(['phone' => '+14155550100']));

        $this->assertRefusedBecause(RecipientUnavailable::INCOMPLETE, fn () => app(AmazonConnector::class)->recipient($account, $this->mapping($account, '171-0000001-0000001')), ['phone_not_a_saudi_mobile']);
    }

    public function test_an_amazon_read_for_the_recipient_is_refused_before_anything_is_sent_while_the_switch_or_the_approval_is_missing(): void
    {
        $account = $this->amazonAccount();
        Http::fake();

        foreach ([true, false] as $switch) {
            config(['sales_channels.buyer_data_transfer_enabled' => $switch]);
            try {
                app(AmazonConnector::class)->recipient($switch ? $account : $this->approved($account), $this->mapping($account, 'O-' . (int) $switch));
                $this->fail('The read should have been refused.');
            } catch (ChannelUnavailableException $exception) {
                $this->assertContains($exception->reason, [ChannelUnavailableException::NOT_APPROVED, ChannelUnavailableException::BUYER_DATA_OFF]);
            }
        }
        $this->assertSame([], $this->sentTo('/orders/'));
    }

    public function test_trendyol_gives_the_shipping_address_of_the_package_and_nothing_else(): void
    {
        $account = $this->approved($this->trendyolAccount());
        Http::fake([self::TY . '/order/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $recipient = app(TrendyolConnector::class)->recipient($account, $this->mapping($account, '10234567890', '3012345678'));

        $this->assertSame(['contact_person_name' => 'Test Buyer', 'phone' => '0500000000', 'address' => '1 Test Street', 'city' => 'Riyadh', 'state' => 'Riyadh Region', 'country' => 'SA', 'email' => '', 'zip' => null], $recipient->shippingAddressData());
        $this->assertStringContainsString('shipmentPackageIds=3012345678', $this->sentTo('/v2/orders')[0]->url());
    }

    public function test_a_package_trendyol_ships_itself_or_that_is_not_in_the_answer_is_refused(): void
    {
        $account = $this->approved($this->trendyolAccount());
        $mapping = $this->mapping($account, '10234567890', '3012345678');

        $this->fakeOnly([self::TY . '/order/*' => Http::response(Trendyol::stream([Trendyol::package(['is4P' => true])]))]);
        $this->assertRefusedBecause(RecipientUnavailable::NOT_SHIPPABLE_BY_US, fn () => app(TrendyolConnector::class)->recipient($account, $mapping));

        $this->fakeOnly([self::TY . '/order/*' => Http::response(Trendyol::stream([Trendyol::package(['orderNumber' => 'OTHER'])]))]);
        $this->assertRefusedBecause(RecipientUnavailable::NOT_FOUND, fn () => app(TrendyolConnector::class)->recipient($account, $mapping));
    }

    public function test_a_recipient_that_cannot_be_shipped_to_is_held_for_correction_by_code(): void
    {
        $account = $this->approved($this->trendyolAccount());
        $package = Trendyol::package();
        $package['shipmentAddress']['phone'] = '';
        $package['shipmentAddress']['countryCode'] = 'AE';
        Http::fake([self::TY . '/order/*' => Http::response(Trendyol::stream([$package]))]);

        $this->assertRefusedBecause(RecipientUnavailable::INCOMPLETE, fn () => app(TrendyolConnector::class)->recipient($account, $this->mapping($account, '10234567890', '3012345678')), ['phone_missing', 'country_not_supported']);
    }

    public function test_the_recipient_never_reaches_a_log_a_dump_or_a_serialised_form(): void
    {
        $recipient = $this->amazonRecipient(['name' => 'Hind Al-Otaibi', 'phone' => '0555123456', 'addressLine1' => 'Prince Sultan Rd 12']);

        $this->assertStringNotContainsString('Hind', print_r($recipient, true));
        $this->assertStringNotContainsString('0555123456', json_encode($recipient));
        $this->expectException(LogicException::class);
        try {
            serialize($recipient);
        } finally {
            $stored = json_encode(SalesChannelApiLog::get()->toArray());
            $this->assertStringNotContainsString('Hind', $stored);
            $this->assertStringNotContainsString('0555123456', $stored);
            $this->assertStringNotContainsString('Prince Sultan', $stored);
        }
    }

    public function test_the_connection_test_and_every_ordinary_read_still_need_no_buyer_approval(): void
    {
        $this->assertTrue(ChannelOperations::readsBuyerData('amazon.order_recipient'));
        $this->assertTrue(ChannelOperations::readsBuyerData('trendyol.order_recipient'));
        $others = array_filter(ChannelOperations::registered(), static fn (string $operation): bool => ChannelOperations::readsBuyerData($operation));
        $this->assertEqualsCanonicalizing(['amazon.order_recipient', 'trendyol.order_recipient'], array_values($others), 'only the two recipient reads need the buyer-data gate');
    }

    public function test_the_buyer_data_approval_is_one_a_person_can_record_on_the_account(): void
    {
        $account = $this->trendyolAccount();

        app(ChannelAccountService::class)->approveScope($account->forceFill(['connection_status' => 'ok', 'connection_checked_at' => now()]), ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, 5, 'Owner approved sending delivery details to the shipping provider only');

        $this->assertSame(5, $account->fresh()->capability_approvals['buyer_data_transfer']['by']);
    }
}
