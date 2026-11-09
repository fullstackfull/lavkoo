<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesChannelRecords;

/**
 * What the tests of the sales-channel screens share: records to look at, the toasts a request flashed, and the check that no stored secret ever reaches a response.
 */
abstract class AdminSalesChannelsTestCase extends AdminPagesTestCase
{
    use CreatesChannelRecords;

    /** Every secret the helpers store, plus the one the Amazon helper uses as the LWA client id. */
    protected const SECRETS = ['key-abc-123', 'secret-xyz-789', 'client-secret-xyz', 'Atzr|refresh-token-abc', 'amzn1.application-oa2-client.test'];

    protected const TY = 'https://apigw.trendyol.com/integration';

    protected function setUp(): void
    {
        parent::setUp();
        ChannelOperations::flush();
        URL::forceRootUrl('https://shop.example.test');
        URL::forceScheme('https');
    }


    protected function assertNoSecretIn(string $text, string $where): void
    {
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $text, "a stored secret reached {$where}");
        }
    }

    /** The account as it is after a successful connection test. */
    protected function connected(SalesChannelAccount $account, array $missing = []): SalesChannelAccount
    {
        $account->forceFill([
            'connection_status' => 'ok',
            'connection_checked_at' => now(),
            'connection_message' => 'Connected.',
            'granted_permissions' => ['orders:read', 'products:read'],
            'missing_permissions' => $missing,
        ])->save();

        return $account->fresh();
    }

    /** An account with writes switched on all the way: the environment, the account, no dry run. */
    protected function liveWritesOn(): void
    {
        config(['sales_channels.external_writes_enabled' => true]);
    }
}
