<?php

namespace Tests\Support;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ReadsRecipient;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use RuntimeException;
use Throwable;

/**
 * A channel whose recipient read throws whatever a test puts in `$throws`: the outage cases (a rate limit, a failure nobody named) without a
 * network and without a real account. Registered by putting it in `sales_channels.connectors.<channel>`.
 */
class UnreachableChannelConnector implements ChannelConnector, ReadsRecipient
{
    public static ?Throwable $throws = null;

    public static int $reads = 0;

    public static function throwing(Throwable $throwable): void
    {
        self::$throws = $throwable;
        self::$reads = 0;
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Trendyol;
    }

    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        return ConnectionTestResult::failed('This connector only answers the recipient read.', ConnectionStatus::Failed);
    }

    public function capabilityMatrix(): array
    {
        return [];
    }

    public function recipient(SalesChannelAccount $account, ExternalOrderMapping $mapping): ChannelRecipient
    {
        self::$reads++;

        throw self::$throws ?? new RuntimeException('The test did not say what to throw.');
    }
}
