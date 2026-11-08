<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/**
 * What a channel's catalogue answered when asked about OUR identifiers — and, above all, WHICH KIND of answer it was.
 *
 * Four answers, and conflating two of them is how a product gets listed twice:
 *
 *  - `MATCHED`   exactly one product in the channel's catalogue carries our identifier. The only answer a machine may act on.
 *  - `SEVERAL`   more than one does. A person chooses; nothing is linked on a guess, because the wrong ASIN is somebody else's product.
 *  - `NONE`      the channel was asked, answered, and holds nothing. A DEFINITE zero.
 *  - `UNKNOWN`   the channel could not be asked at all: a switch is off, the call was a rehearsal, it failed, it was rate limited.
 *
 * **`UNKNOWN` IS NOT `NONE`.** The whole class exists for that sentence. "I could not find out" read as "it is not there" is what makes a second listing of a product that was
 * already listed, and the one thing the owner asked never to happen. `saysNothingIsThere()` answers true for `NONE` alone, and every caller must go through it rather than
 * test for an empty candidate list — an empty list is also what a failed call returns.
 */
final readonly class CatalogMatch
{
    public const MATCHED = 'matched';
    public const SEVERAL = 'several';
    public const NONE = 'none';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<CatalogCandidate> $candidates
     * @param array<string, string> $askedWith the identifiers the channel was asked about, by kind
     */
    public function __construct(
        public string $state,
        public array $candidates = [],
        public array $askedWith = [],
        public ?CarbonInterface $readAt = null,
        /** Why the channel could not be asked, when `state` is `UNKNOWN`: a code of ours, never a channel's sentence. */
        public ?string $reason = null,
    ) {
    }

    /** @param array<string, string> $askedWith */
    public static function unknown(string $reason, array $askedWith = []): self
    {
        return new self(self::UNKNOWN, askedWith: $askedWith, reason: $reason);
    }

    /** @param array<string, string> $askedWith */
    public static function none(array $askedWith = []): self
    {
        return new self(self::NONE, askedWith: $askedWith, readAt: now());
    }

    /**
     * @param list<CatalogCandidate> $candidates
     * @param array<string, string> $askedWith
     */
    public static function of(array $candidates, array $askedWith = []): self
    {
        $candidates = array_values($candidates);

        return match (count($candidates)) {
            0 => self::none($askedWith),
            1 => new self(self::MATCHED, $candidates, $askedWith, now()),
            default => new self(self::SEVERAL, $candidates, $askedWith, now()),
        };
    }

    /** The one candidate, and only when there is exactly one. `SEVERAL` gives nothing: choosing for the owner is what this refuses to do. */
    public function theOne(): ?CatalogCandidate
    {
        return $this->state === self::MATCHED ? $this->candidates[0] : null;
    }

    /**
     * True ONLY for a definite zero from the channel. False for `UNKNOWN`, whatever the candidate list looks like.
     *
     * This is the method a caller asks before it creates anything.
     */
    public function saysNothingIsThere(): bool
    {
        return $this->state === self::NONE;
    }

    /** True when the channel could not be asked. Nothing may be created or linked on the strength of it. */
    public function couldNotAsk(): bool
    {
        return $this->state === self::UNKNOWN;
    }

    public function needsAPerson(): bool
    {
        return $this->state === self::SEVERAL;
    }

    /** @return array<string, mixed> what is kept on the draft, so a screen can show the answer without asking the channel again */
    public function forTheRecord(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'asked_with' => $this->askedWith,
            'read_at' => $this->readAt?->toIso8601String(),
            'candidates' => array_map(static fn (CatalogCandidate $candidate): array => [
                'external_id' => $candidate->externalId,
                'title' => $candidate->title,
                'brand' => $candidate->brand,
                'product_type' => $candidate->productType,
                'identifiers' => $candidate->identifiers,
                'image_url' => $candidate->imageUrl,
                'marketplace_id' => $candidate->marketplaceId,
                'offered_by_anyone' => $candidate->offeredByAnyone,
            ], $this->candidates),
        ];
    }
}
