<?php

namespace Tests\Support;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\ListingSubmission;
use RuntimeException;
use Throwable;

/** A channel that can create listings and throws whatever a test puts in `$throws` when asked to: the outage cases, without a network. */
class ThrowingListingConnector implements ChannelConnector, CreatesListings
{
    public static ?Throwable $throws = null;

    public static int $calls = 0;

    public static function throwing(Throwable $throwable): void
    {
        self::$throws = $throwable;
        self::$calls = 0;
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Amazon;
    }

    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        return ConnectionTestResult::failed('This connector only answers the listing write.', ConnectionStatus::Failed);
    }

    public function capabilityMatrix(): array
    {
        return [];
    }

    public function putListing(SalesChannelAccount $account, ChannelListingDraft $draft): ListingSubmission
    {
        self::$calls++;

        throw self::$throws ?? new RuntimeException('The test did not say what to throw.');
    }

    public function listingSubmission(SalesChannelAccount $account, ChannelListingDraft $draft): ListingSubmission
    {
        self::$calls++;

        throw self::$throws ?? new RuntimeException('The test did not say what to throw.');
    }
}
