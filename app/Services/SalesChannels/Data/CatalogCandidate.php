<?php

namespace App\Services\SalesChannels\Data;

/**
 * One product in a channel's own catalogue that MIGHT be ours: what the channel calls it, and the identity it carries.
 *
 * It is a proposal and never a link. The identifiers are the channel's, not ours; `productType` is the channel's own word for the kind of thing it is, and it is the one field
 * that earns this call its keep, because the requirements of a listing cannot be asked for without it.
 */
final readonly class CatalogCandidate
{
    /**
     * @param array<string, string> $identifiers the channel's identifiers for it, by kind (EAN, UPC, GTIN…)
     * @param list<string> $browseNodes the channel's own classification ids, where it gives them
     */
    public function __construct(
        public string $externalId,
        public ?string $title = null,
        public ?string $brand = null,
        public ?string $productType = null,
        public array $identifiers = [],
        public ?string $imageUrl = null,
        public array $browseNodes = [],
        public ?string $marketplaceId = null,
        /** True when the channel says someone already offers it. It does NOT mean WE offer it: our own listing is `getListingsItem`'s business, not the catalogue's. */
        public bool $offeredByAnyone = false,
    ) {
    }

    /** Whether this candidate carries the one thing a listing cannot be built without. */
    public function namesItsProductType(): bool
    {
        return $this->productType !== null && $this->productType !== '';
    }
}
