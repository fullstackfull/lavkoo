<?php

namespace Tests\Support;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\ChannelAccountService;

/** Channel accounts as the admin would have set them up: credentials stored encrypted, then switched on with the capabilities (and approvals) a test needs. */
trait CreatesChannelAccounts
{
    private const ALL_CAPABILITIES = ['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'inventory_publishing', 'price_publishing', 'fulfillment_dispatch', 'financial_reconciliation'];

    protected function trendyolAccount(array $capabilities = self::ALL_CAPABILITIES, bool $writes = true, string $sellerId = '1234567'): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => $sellerId, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'carrier_codes' => ['smsa' => 'SMSA']]],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );

        return $this->switchOn($account, $capabilities, $writes);
    }

    protected function amazonAccount(array $capabilities = self::ALL_CAPABILITIES, bool $writes = true): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'amazon', 'name' => 'Amazon KSA', 'external_account_id' => 'A1SELLER', 'currency' => 'SAR', 'settings' => ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP']]],
            ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
            adminId: 1,
        );

        return $this->switchOn($account, $capabilities, $writes);
    }

    protected function noonAccount(array $capabilities = self::ALL_CAPABILITIES, bool $writes = true, string $partnerCode = 'partner-1'): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon KSA', 'external_account_id' => $partnerCode, 'currency' => 'SAR', 'settings' => ['warehouse_code' => 'WH-RUH-01', 'country_code' => 'sa']],
            ['key_id' => 'key-abc', 'private_key' => self::aTestPrivateKey(), 'project_code' => 'proj_x', 'channel_identifier' => 'noon'],
            adminId: 1,
        );

        return $this->switchOn($account, $capabilities, $writes);
    }

    /** A real RSA key, generated here: noon's sign-in is signed with it, so a placeholder would fail before a test proved anything. */
    private static function aTestPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /** The order import of these accounts has just started and finished a complete sweep: the proof a quantity of their listings needs before it may be raised. */
    protected function completeOrderSweep(SalesChannelAccount ...$accounts): void
    {
        foreach ($accounts as $account) {
            SalesChannelSyncState::updateOrCreate(['account_id' => $account->id, 'resource' => 'orders'], ['last_attempt_at' => now(), 'last_success_at' => now(), 'cursor' => null]);
        }
    }

    private function switchOn(SalesChannelAccount $account, array $capabilities, bool $writes): SalesChannelAccount
    {
        $account->forceFill([
            'enabled' => true,
            'write_enabled' => $writes,
            'dry_run' => !$writes,
            'capabilities' => $capabilities,
            'capability_approvals' => $writes ? ['inventory_publishing' => ['by' => 1], 'price_publishing' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1], 'webhook_management' => ['by' => 1]] : [],
        ])->save();

        return $account->fresh();
    }
}
