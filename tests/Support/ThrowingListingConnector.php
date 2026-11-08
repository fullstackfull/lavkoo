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

    /** true: the read says the channel holds nothing (so a publish reaches the write, which throws). */
    public static bool $holdsNothing = false;

    public static function throwing(Throwable $throwable, bool $onTheWriteOnly = false): void
    {
        self::$throws = $throwable;
        self::$calls = 0;
        self::$holdsNothing = $onTheWriteOnly;
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

    /** @return list<string> */
    public function offerAttributes(): array
    {
        return ['price', 'quantity'];
    }

    public function createListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission
    {
        return $this->refuse();
    }

    public function updateListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $changedAttributes): ListingSubmission
    {
        return $this->refuse();
    }

    /** What the channel holds: a test that wants the write to be reached says so with `$holdsNothing`, otherwise this throws too. */
    public function listingFor(SalesChannelAccount $account, string $sku): ListingSubmission
    {
        if (self::$holdsNothing) {
            return ListingSubmission::working(null, 'not_there');
        }

        return $this->refuse();
    }

    private function refuse(): never
    {
        self::$calls++;

        throw self::$throws ?? new RuntimeException('The test did not say what to throw.');
    }
}
