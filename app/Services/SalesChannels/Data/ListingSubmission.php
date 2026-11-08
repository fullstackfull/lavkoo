<?php

namespace App\Services\SalesChannels\Data;

/**
 * What a channel said about a listing — and the difference between the two things it can say.
 *
 *  - ACCEPTED_FOR_PROCESSING: the channel TOOK the submission. It is not a listing, not a product, and not a promise that either will exist: Amazon answers `ACCEPTED` to a
 *    `putListingsItem` that it has merely queued, and the listing can still be refused, held for review or left incomplete afterwards.
 *  - LIVE: the channel, asked afresh, reports a listing for our own SKU. `statuses` are its own words about it (Amazon's summaries: BUYABLE, DISCOVERABLE), so that "the
 *    listing exists" and "the listing is sellable" are never confused — a listing we deliberately created without an offer exists and must NOT be buyable.
 *  - WORKING: the channel is still busy, or could not be asked. Nothing is sent again on the strength of it; it is asked about instead.
 *  - REJECTED: refused, with the channel's own reasons.
 *
 * `id` is the channel's identifier for the attempt (Amazon's submissionId, Trendyol's batchRequestId) — what a later call asks about.
 */
final readonly class ListingSubmission
{
    /** The channel took the submission. NOT a finished listing. */
    public const ACCEPTED_FOR_PROCESSING = 'accepted_for_processing';
    /** The channel reports a listing for our SKU. */
    public const LIVE = 'live';
    public const REJECTED = 'rejected';
    /** The channel is still busy with something, or answered in a way that settles nothing. NOT "it holds nothing". */
    public const WORKING = 'working';
    /** The channel says, plainly, that it holds NOTHING for this SKU (Amazon: 404). The only answer a create may be made on. */
    public const ABSENT = 'absent';
    /** The channel could not be asked at all (a failed call, a rehearsal): NOT an answer, and never read as "it holds nothing". */
    public const UNKNOWN = 'unknown';

    /** Amazon's summary statuses, as it writes them. */
    public const BUYABLE = 'BUYABLE';
    public const DISCOVERABLE = 'DISCOVERABLE';

    /**
     * @param list<string> $problems the channel's own reasons, as it gave them
     * @param list<string> $statuses what the channel says about the listing itself (only with LIVE)
     */
    public function __construct(
        public string $status,
        public ?string $id = null,
        public array $problems = [],
        public ?string $channelStatus = null,
        public ?string $listingKey = null,
        public array $statuses = [],
    ) {
    }

    public static function acceptedForProcessing(?string $id = null, ?string $channelStatus = null): self
    {
        return new self(self::ACCEPTED_FOR_PROCESSING, $id, [], $channelStatus);
    }

    /** @param list<string> $statuses */
    public static function live(?string $listingKey, array $statuses = [], ?string $id = null): self
    {
        return new self(self::LIVE, $id, [], implode(',', $statuses) ?: null, $listingKey, $statuses);
    }

    /** @param list<string> $problems */
    public static function rejected(array $problems, ?string $id = null, ?string $channelStatus = null): self
    {
        return new self(self::REJECTED, $id, $problems, $channelStatus);
    }

    public static function working(?string $id = null, ?string $channelStatus = null): self
    {
        return new self(self::WORKING, $id, [], $channelStatus);
    }

    public static function unknown(?string $channelStatus = null): self
    {
        return new self(self::UNKNOWN, null, [], $channelStatus);
    }

    /** The channel knows no listing of ours by this SKU and said so. */
    public static function absent(?string $channelStatus = 'not_there'): self
    {
        return new self(self::ABSENT, null, [], $channelStatus);
    }

    /** May a listing be CREATED? Only when the channel has said it holds nothing; silence, a half answer and a failure are all not this. */
    public function isAbsent(): bool
    {
        return $this->status === self::ABSENT;
    }

    /** Nobody knows: the call failed, or it was a rehearsal. A caller that was about to write must stop. */
    public function isUnknown(): bool
    {
        return $this->status === self::UNKNOWN;
    }

    public function wasTaken(): bool
    {
        return $this->status === self::ACCEPTED_FOR_PROCESSING;
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    /** Does the channel say the listing can be bought? For a listing created without an offer of ours, that is a surprise worth reporting, not a success. */
    public function isBuyable(): bool
    {
        return in_array(self::BUYABLE, $this->statuses, true);
    }
}
