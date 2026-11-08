<?php

namespace App\Services\SalesChannels\Data;

/**
 * What a channel said about one attempt to create or replace a listing.
 *
 * `id` is the channel's own identifier for the attempt (Amazon's submissionId, Trendyol's batchRequestId) — what a later call asks about. A channel that answers with neither an
 * acceptance nor a refusal is ACCEPTED_LATER: nothing is sent again, the attempt is asked about instead.
 */
final readonly class ListingSubmission
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const WORKING = 'working';

    /** @param list<string> $problems the channel's own reasons, as it gave them */
    public function __construct(
        public string $status,
        public ?string $id = null,
        public array $problems = [],
        public ?string $channelStatus = null,
        public ?string $listingKey = null,
    ) {
    }

    public static function accepted(?string $id = null, ?string $listingKey = null, ?string $channelStatus = null): self
    {
        return new self(self::ACCEPTED, $id, [], $channelStatus, $listingKey);
    }

    /** @param list<string> $problems */
    public static function rejected(array $problems, ?string $id = null, ?string $channelStatus = null): self
    {
        return new self(self::REJECTED, $id, $problems, $channelStatus);
    }

    public static function working(?string $id, ?string $channelStatus = null): self
    {
        return new self(self::WORKING, $id, [], $channelStatus);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
