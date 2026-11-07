<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelSyncState;
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

    private function trendyol(array $secrets = ['api_key' => 'key-aaa111', 'api_secret' => 'secret-bbb222'], string $sellerId = '123456'): SalesChannelAccount
    {
        return $this->accounts->create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => $sellerId], $secrets, adminId: 1);
    }

    private function verified(SalesChannelAccount $account): SalesChannelAccount
    {
        return $this->accounts->recordConnection($account, new ConnectionTestResult(ConnectionStatus::Ok, 'Connected.', ['orders:read'], [], [], $account->external_account_id));
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

    /** The blob as another application key leaves it: still there, but nobody here can read it. */
    private function withUnreadableCredentials(SalesChannelAccount $account): string
    {
        $foreign = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['api_key' => 'key-aaa111', 'api_secret' => 'secret-bbb222']));
        $tampered = substr($foreign, 0, -6) . 'AAAAAA';
        DB::table('sales_channel_accounts')->where('id', $account->id)->update(['credentials' => $tampered]);

        return $tampered;
    }

    public function test_credentials_nobody_can_read_are_not_destroyed_by_a_rename_or_by_typing_only_some_of_them(): void
    {
        $account = $this->trendyol();
        $unreadable = $this->withUnreadableCredentials($account);
        $this->assertSame([], $this->credentials->get($account->fresh())->presence(), 'They read as unset.');

        $this->accounts->update($account->fresh(), ['name' => 'Renamed only'], [], [], 1);
        $this->assertSame('Renamed only', $account->fresh()->name);
        $this->assertSame($unreadable, DB::table('sales_channel_accounts')->where('id', $account->id)->value('credentials'), 'A rename does not touch the credentials at all.');

        try {
            $this->accounts->update($account->fresh(), ['name' => 'Not saved'], ['api_key' => 'key-typed-alone'], [], 1);
            $this->fail('A partial set replaced credentials that might be recoverable.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertStringContainsString('cannot be decrypted', $exception->getMessage());
        }
        $this->assertSame($unreadable, DB::table('sales_channel_accounts')->where('id', $account->id)->value('credentials'), 'The old ciphertext is still there: the right key still opens it.');
        $this->assertSame('Renamed only', $account->fresh()->name, 'A refused write changed nothing else either.');
    }

    public function test_a_complete_new_set_replaces_credentials_nobody_can_read(): void
    {
        $account = $this->trendyol();
        $this->withUnreadableCredentials($account);

        $this->accounts->update($account->fresh(), [], ['api_key' => 'key-new-1', 'api_secret' => 'secret-new-2'], [], 1);

        $this->assertSame(['key-new-1', 'secret-new-2'], [$this->credentials->get($account->fresh())->secret('api_key'), $this->credentials->get($account->fresh())->secret('api_secret')]);
    }

    public function test_changing_the_refresh_token_drops_the_access_token_obtained_with_the_old_one(): void
    {
        $account = $this->accounts->create(['channel' => 'amazon', 'name' => 'Amazon', 'external_account_id' => 'A1', 'settings' => ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']]], ['lwa_client_id' => 'cid', 'lwa_client_secret' => 'csecret', 'refresh_token' => 'rtoken-old'], 1);
        $this->credentials->putRuntime($account, 'access_token', 'Atza|OLD-TOKEN');
        $this->credentials->putRuntime($account, 'access_token_expires_at', now()->addMinutes(50)->toIso8601String());
        $this->assertSame('Atza|OLD-TOKEN', $this->credentials->get($account->fresh())->secret('access_token'));

        $this->accounts->update($account->fresh(), [], ['refresh_token' => 'rtoken-new'], [], 1);

        $now = $this->credentials->get($account->fresh());
        $this->assertSame('rtoken-new', $now->secret('refresh_token'));
        $this->assertNull($now->secret('access_token'), 'The old token belongs to the old authorisation.');
        $this->assertNull($now->secret('access_token_expires_at'));
        $this->assertSame('cid', $now->secret('lwa_client_id'), 'The secrets that did not change stay.');
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

    public function test_switching_a_capability_off_withdraws_the_separate_approvals_that_belong_to_it(): void
    {
        $account = $this->verified($this->trendyol());
        foreach ([Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport] as $capability) {
            $this->accounts->enableCapability($account->fresh(), $capability, 1);
        }
        foreach (['product_writes', 'webhook_management', 'notification_management'] as $scope) {
            $this->accounts->approveScope($account->fresh(), $scope, 1, 'reviewed');
        }

        $this->accounts->disableCapability($account->fresh(), Capability::OrderImport, 1);
        $afterOrderImport = $account->fresh()->capability_approvals;
        $this->assertSame(['product_writes'], array_keys($afterOrderImport), 'Webhook and notification approvals went with Order Import; the product-writes approval belongs to Product Sync.');

        $this->accounts->disableCapability($account->fresh(), Capability::ProductSync, 1);
        $this->assertSame([], $account->fresh()->capability_approvals);

        $this->accounts->enableCapability($account->fresh(), Capability::OrderImport, 1);
        $this->assertArrayNotHasKey('webhook_management', $account->fresh()->capability_approvals, 'Switching a capability back on does not bring an old approval back.');
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

    // ---- changing who the account is -------------------------------------------------------------------

    /** An account that has earned everything: tested, switched on up to publishing and dispatch, approved, writing for real. */
    private function fullyTrusted(string $sellerId = '123456'): SalesChannelAccount
    {
        $account = $this->verified($this->trendyol(sellerId: $sellerId));
        $this->accounts->setEnabled($account, true, 1);
        foreach ([Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport, Capability::InventoryPublishing, Capability::FulfillmentDispatch] as $capability) {
            $this->accounts->enableCapability($account, $capability, 1, $capability->needsApproval() ? 'approved for the tests' : null);
        }
        $this->accounts->approveScope($account, 'webhook_management', 1, 'for the tests');
        $this->accounts->setWriteEnabled($account, true, 1);
        $this->accounts->setDryRun($account, false, 1);

        return $account->fresh();
    }

    public function test_the_columns_the_connectors_read_follow_the_settings_when_they_change(): void
    {
        $amazon = $this->accounts->create(['channel' => 'amazon', 'name' => 'Amazon', 'external_account_id' => 'A1', 'settings' => ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']]], ['lwa_client_id' => 'a', 'lwa_client_secret' => 'b', 'refresh_token' => 'c'], 1);

        $this->accounts->update($amazon, ['settings' => ['marketplace_ids' => ['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG'], 'region' => 'eu']], [], [], 1);

        $this->assertSame(['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG'], $amazon->fresh()->marketplace_ids, 'AmazonConnector reads this column, not the settings.');

        $trendyol = $this->trendyol();
        $this->accounts->update($trendyol, ['settings' => ['storefront_code' => 'AE']], [], [], 1);
        $this->assertSame(['AE', ['AE']], [$trendyol->fresh()->market, $trendyol->fresh()->marketplace_ids]);
    }

    public function test_pointing_the_account_at_another_seller_withdraws_everything_that_was_proven_or_approved_for_the_old_one(): void
    {
        $account = $this->fullyTrusted();
        $this->assertTrue($account->write_enabled);
        $this->assertFalse($account->dry_run);
        $this->assertNotEmpty($account->capability_approvals);

        $this->accounts->update($account, ['external_account_id' => '999999'], [], [], 1);

        $fresh = $account->fresh();
        $this->assertSame(ConnectionStatus::Untested, $fresh->connectionStatus());
        $this->assertNull($fresh->connection_checked_at);
        $this->assertSame(['connection_test'], $fresh->capabilities, 'Only the connection test stays on; every other step has to be earned again.');
        $this->assertSame([], $fresh->capability_approvals);
        $this->assertFalse($fresh->write_enabled);
        $this->assertTrue($fresh->dry_run);
        $this->assertTrue($fresh->enabled, 'The account itself is not switched off.');
    }

    public function test_what_was_read_for_the_old_seller_is_not_carried_over_to_the_new_one(): void
    {
        $account = $this->fullyTrusted();
        SalesChannelSyncState::create([
            'account_id' => $account->id, 'resource' => 'orders', 'watermark' => now()->subMinutes(5), 'cursor' => ['page' => 3], 'last_success_at' => now()->subMinutes(5), 'last_attempt_at' => now()->subMinutes(5),
            'consecutive_failures' => 2, 'last_error' => 'HTTP 500', 'backoff_until' => now()->addMinutes(20),
        ]);
        $other = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Other', 'external_account_id' => '777']);
        SalesChannelSyncState::create(['account_id' => $other->id, 'resource' => 'orders', 'watermark' => now()->subMinutes(5)]);

        $this->accounts->update($account, ['external_account_id' => '999999'], [], [], 1);

        $state = SalesChannelSyncState::where(['account_id' => $account->id, 'resource' => 'orders'])->firstOrFail();
        $this->assertNull($state->watermark, 'The new seller starts from its own backfill, not from where the old one was read up to.');
        $this->assertNull($state->cursor);
        $this->assertNull($state->backoff_until);
        $this->assertSame([0, null], [$state->consecutive_failures, $state->last_error]);
        $this->assertNotNull(SalesChannelSyncState::where('account_id', $other->id)->firstOrFail()->watermark, 'Another account is not touched.');
    }

    public function test_a_change_of_storefront_or_environment_is_a_new_identity_too(): void
    {
        $storefront = $this->fullyTrusted();
        $this->accounts->update($storefront, ['settings' => ['storefront_code' => 'AE']], [], [], 1);
        $this->assertSame(ConnectionStatus::Untested, $storefront->fresh()->connectionStatus());
        $this->assertFalse($storefront->fresh()->write_enabled);

        $environment = $this->fullyTrusted(sellerId: '555555');
        $this->accounts->update($environment, ['environment' => 'sandbox'], [], [], 1);
        $this->assertSame([], $environment->fresh()->capability_approvals);
        $this->assertTrue($environment->fresh()->dry_run);
    }

    public function test_saving_the_same_identity_again_keeps_what_was_earned(): void
    {
        $account = $this->fullyTrusted();

        $this->accounts->update($account, ['name' => 'Renamed', 'external_account_id' => '123456', 'settings' => ['integration_name' => 'OtherName']], [], [], 1);

        $fresh = $account->fresh();
        $this->assertSame(ConnectionStatus::Ok, $fresh->connectionStatus());
        $this->assertTrue($fresh->write_enabled);
        $this->assertContains('fulfillment_dispatch', $fresh->capabilities);
        $this->assertNotEmpty($fresh->capability_approvals);
    }
}
