<?php

namespace App\Services\SalesChannels\Listings;

/** What one attempt to put a product on a channel came to. `reason` is a stable code a screen and a command can act on; `problems` are the field names or the channel's own words. */
final readonly class ListingOutcome
{
    public const VALIDATED = 'validated';
    public const SUBMITTED = 'submitted';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const REFUSED = 'refused';
    public const WORKING = 'working';

    /** @param list<string> $problems */
    private function __construct(public string $state, public ?string $reason = null, public array $problems = [], public ?int $listingId = null)
    {
    }

    /** @param list<string> $problems */
    public static function refused(string $reason, array $problems = []): self
    {
        return new self(self::REFUSED, $reason, $problems);
    }

    /** @param list<string> $problems */
    public static function validated(array $problems = []): self
    {
        return new self(self::VALIDATED, null, $problems);
    }

    public static function submitted(): self
    {
        return new self(self::SUBMITTED);
    }

    public static function working(): self
    {
        return new self(self::WORKING);
    }

    /** @param list<string> $problems what the channel reports that a person has to look at, although the listing exists */
    public static function accepted(?int $listingId, array $problems = []): self
    {
        return new self(self::ACCEPTED, problems: $problems, listingId: $listingId);
    }

    /** @param list<string> $problems */
    public static function rejected(array $problems): self
    {
        return new self(self::REJECTED, 'channel_refused_the_listing', $problems);
    }

    public function ok(): bool
    {
        return $this->state !== self::REFUSED && $this->state !== self::REJECTED;
    }
}
