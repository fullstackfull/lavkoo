<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/** The credential values below are throwaway strings made up for the tests. */
class ChannelAccountServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private ChannelAccountService $accounts;

    private ChannelCredentialStore $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->accounts = app(ChannelAccountService::class);
        $this->credentials = app(ChannelCredentialStore::class);
    }

    private function trendyol(array $secrets = ['api_key' => 'key-aaa111', 'api_secret' => 'secret-bbb222']): SalesChannelAccount
    {
        return $this->accounts->create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => '123456'], $secrets, adminId: 1);
    }

    private function verified(SalesChannelAccount $account): SalesChannelAccount
    {
        return $this->accounts->recordConnection($account, new ConnectionTestResult(ConnectionStatus::Ok, 'Connected.', ['orders:read'], [], [], '123456'));
    }

    public function test_a_new_account_does_nothing_until_each_step_is_taken(): void
    {
        $account = $this->trendyol();

        $this->assertFalse($account->enabled);
        $this->assertFalse($account->write_enabled);
        $this->assertTrue($account->dry_run);
        $this->assertSame([], $account->capabilities);
        $this->assertSame([], $account->capability_approvals);
        $this->assertSame('manual_review', $account->execution_policy);
        $this->assertSame(ConnectionStatus::Untested, $account->connectionStatus());
    }

    public function test_credentials_are_stored_encrypted_and_never_leave_the_store(): void
    {
        $account = $this->trendyol();

        $raw = (string) DB::table('sales_channel_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('key-aaa111', $raw);
        $this->assertStringNotContainsString('secret-bbb222', $raw);
        $this->assertStringNotContainsString('key-aaa111', json_encode($account->toArray()));
        $this->assertStringNotContainsString('key-aaa111', print_r($this->credentials->get($account), true));
        $this->assertSame('key-aaa111', $this->credentials->get($account)->secret('api_key'));
        $this->assertSame(['api_key' => true, 'api_secret' => true], $this->credentials->get($account)->presence());
    }

    public function test_a_blank_secret_on_save_keeps_the_stored_one_and_a_clear_removes_it(): void
    {
        $account = $this->trendyol();

        $this->accounts->update($account, [], ['api_key' => '', 'api_secret' => 'secret-new'], [], 1);
        $this->assertSame('key-aaa111', $this->credentials->get($account->fresh())->secret('api_key'));
        $this->assertSame('secret-new', $this->credentials->get($account->fresh())->secret('api_secret'));

        $this->accounts->update($account->fresh(), [], [], ['api_secret'], 1);
        $this->assertFalse($this->credentials->get($account->fresh())->has('api_secret'));
    }

    public function test_a_credential_the_channel_does_not_have_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->accounts->create(['channel' => 'trendyol', 'name' => 'X'], ['lwa_client_secret' => 'nope'], 1);
    }

    public function test_new_credentials_have_to_be_proven_again(): void
    {
        $account = $this->verified($this->trendyol());
        $this->assertTrue($account->fresh()->connectionStatus()->isUsable());

        $this->accounts->update($account->fresh(), [], ['api_key' => 'key-changed'], [], 1);

        $this->assertSame(ConnectionStatus::Untested, $account->fresh()->connectionStatus());
    }

    public function test_capabilities_are_switched_on_in_order_and_only_after_the_connection_is_proven(): void
    {
        $account = $this->trendyol();
        $this->accounts->enableCapability($account, Capability::ConnectionTest, 1);

        try {
            $this->accounts->enableCapability($account->fresh(), Capability::ReadOnlySync, 1);
            $this->fail('Read-only sync was switched on before the connection was proven.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::AUTH_NEEDED, $exception->reason);
        }

        $this->verified($account->fresh());
        $this->accounts->enableCapability($account->fresh(), Capability::ReadOnlySync, 1);

        try {
            $this->accounts->enableCapability($account->fresh(), Capability::InventoryPublishing, 1, 'ok');
            $this->fail('Inventory publishing was switched on without product sync.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::CAPABILITY_OFF, $exception->reason);
        }
    }

    public function test_a_write_capability_needs_a_reason_and_a_recent_proven_connection(): void
    {
        $account = $this->verified($this->trendyol());
        foreach ([Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync] as $capability) {
            $this->accounts->enableCapability($account->fresh(), $capability, 1);
        }

        try {
            $this->accounts->enableCapability($account->fresh(), Capability::PricePublishing, 1);
            $this->fail('A write capability was switched on without an approval.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::NOT_APPROVED, $exception->reason);
        }

        $account->fresh()->forceFill(['connection_checked_at' => now()->subDays(40)])->save();
        try {
            $this->accounts->enableCapability($account->fresh(), Capability::PricePublishing, 1, 'prices checked against the sandbox');
            $this->fail('A stale connection test was accepted.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::AUTH_NEEDED, $exception->reason);
        }

        $this->verified($account->fresh());
        $enabled = $this->accounts->enableCapability($account->fresh(), Capability::PricePublishing, 7, 'prices checked against the sandbox');

        $this->assertTrue($enabled->hasCapability(Capability::PricePublishing));
        $this->assertSame(7, $enabled->capability_approvals['price_publishing']['by']);
        $this->assertSame('prices checked against the sandbox', $enabled->capability_approvals['price_publishing']['reason']);
    }

    public function test_a_write_capability_is_refused_while_the_account_is_missing_permissions_at_the_channel(): void
    {
        $account = $this->trendyol();
        $this->accounts->recordConnection($account, new ConnectionTestResult(ConnectionStatus::Ok, 'Connected, but limited.', ['orders:read'], ['inventory:write'], [], '123456'));
        foreach ([Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync] as $capability) {
            $this->accounts->enableCapability($account->fresh(), $capability, 1);
        }

        $this->expectException(ChannelUnavailableException::class);
        $this->accounts->enableCapability($account->fresh(), Capability::InventoryPublishing, 1, 'because');
    }

    public function test_switching_a_capability_off_switches_off_what_depends_on_it_and_withdraws_their_approvals(): void
    {
        $account = $this->verified($this->trendyol());
        foreach ([Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync] as $capability) {
            $this->accounts->enableCapability($account->fresh(), $capability, 1);
        }
        $this->accounts->enableCapability($account->fresh(), Capability::InventoryPublishing, 1, 'approved');
        $this->accounts->enableCapability($account->fresh(), Capability::PricePublishing, 1, 'approved');

        $off = $this->accounts->disableCapability($account->fresh(), Capability::ProductSync, 1);

        $this->assertEqualsCanonicalizing(['connection_test', 'read_only_sync'], $off->capabilities);
        $this->assertSame([], $off->capability_approvals);
    }

    public function test_writing_can_only_be_allowed_on_a_proven_account_and_other_scopes_need_their_own_approval(): void
    {
        $account = $this->trendyol();

        try {
            $this->accounts->setWriteEnabled($account, true, 1);
            $this->fail('Writes were allowed on an untested account.');
        } catch (ChannelUnavailableException) {
        }

        $this->verified($account);
        $this->assertTrue($this->accounts->setWriteEnabled($account->fresh(), true, 1)->write_enabled);

        $this->accounts->approveScope($account->fresh(), 'product_writes', 1, 'catalogue import reviewed');
        $this->assertArrayHasKey('product_writes', $account->fresh()->capability_approvals);

        $this->expectException(InvalidArgumentException::class);
        $this->accounts->approveScope($account->fresh(), 'price_publishing', 1, 'must go through the capability');
    }

    public function test_an_amazon_account_gets_its_own_fba_location_that_is_not_shared_stock(): void
    {
        $account = $this->accounts->create(['channel' => 'amazon', 'name' => 'Amazon KSA', 'settings' => ['region' => 'eu', 'marketplace_ids' => ['a17e79c6d8dwnp']]], ['lwa_client_id' => 'cid', 'lwa_client_secret' => 'csecret', 'refresh_token' => 'rtoken'], 1);

        $location = InventoryLocation::where('sales_channel_account_id', $account->id)->first();
        $this->assertNotNull($location);
        $this->assertSame('amazon_fba', $location->type);
        $this->assertFalse($location->storefront_sellable);
        $this->assertSame(['A17E79C6D8DWNP'], $account->settings['marketplace_ids']);
    }

    public function test_settings_are_validated_and_unknown_keys_are_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->accounts->create(['channel' => 'trendyol', 'name' => 'X', 'settings' => ['storefront_code' => 'ZZ']], [], 1);
    }

    public function test_every_change_is_audited_without_a_secret_in_the_trail(): void
    {
        DB::statement('create table audit_logs (id integer primary key autoincrement, actor_type text, actor_id integer, actor_name text, action text, subject_type text, subject_id text, before text, after text, context text, ip_address text, user_agent text, created_at text, updated_at text)');
        $account = $this->trendyol();
        $this->accounts->setEnabled($account, true, 1);

        $rows = DB::table('audit_logs')->pluck('action')->all();
        $this->assertContains('sales_channel.account_created', $rows);
        $this->assertContains('sales_channel.account_enabled', $rows);
        $trail = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('key-aaa111', $trail);
        $this->assertStringNotContainsString('secret-bbb222', $trail);
    }
}
