<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\SalesChannels\Data\CatalogCandidate;

/**
 * Turns one `searchCatalogItems` / `getCatalogItem` item into a candidate.
 *
 * Amazon returns most of an item as a list keyed by marketplace (`summaries`, `productTypes`, `images`, `identifiers`), because the same ASIN is a different offer in every
 * marketplace. Only the entry for the marketplace we asked about is read; the rest belongs to a market we are not selling in, and taking a `productType` from the wrong one
 * would ask for the requirements of a listing we are not making.
 */
class AmazonCatalogMapper
{
    /** @param array<string, mixed> $item one element of `items[]`, or the whole body of a `getCatalogItem` */
    public function map(array $item, string $marketplaceId): CatalogCandidate
    {
        $summary = $this->forMarketplace((array) ($item['summaries'] ?? []), $marketplaceId);
        $productType = $this->forMarketplace((array) ($item['productTypes'] ?? []), $marketplaceId);

        return new CatalogCandidate(
            externalId: (string) ($item['asin'] ?? ''),
            title: $this->text($summary['itemName'] ?? null),
            brand: $this->text($summary['brand'] ?? $summary['manufacturer'] ?? null),
            // `summaries[].websiteDisplayGroup` is a shop department, not a product type: it must never stand in for one, because the requirements call would refuse it.
            productType: $this->text($productType['productType'] ?? null),
            identifiers: $this->identifiers((array) ($item['identifiers'] ?? []), $marketplaceId),
            imageUrl: $this->mainImage((array) ($item['images'] ?? []), $marketplaceId),
            browseNodes: $this->browseNodes((array) ($item['salesRanks'] ?? []), $marketplaceId),
            marketplaceId: $marketplaceId,
            // Amazon says how many offers exist, not whose. So this says "somebody offers it", which is all it can honestly say.
            offeredByAnyone: $this->isOffered($summary),
        );
    }

    /**
     * The entry for one marketplace. Amazon has used both `marketplaceId` and (in older shapes) no key at all when a request named a single marketplace, so an entry without
     * the key is taken as the one asked for rather than discarded — discarding it would lose the product type and send the caller back to a person for nothing.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function forMarketplace(array $entries, string $marketplaceId): array
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string) ($entry['marketplaceId'] ?? '');
            if ($id === $marketplaceId || $id === '') {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, string> kind => value, e.g. EAN => 590…
     */
    private function identifiers(array $entries, string $marketplaceId): array
    {
        $found = [];
        foreach ((array) ($this->forMarketplace($entries, $marketplaceId)['identifiers'] ?? []) as $identifier) {
            $kind = mb_strtoupper(trim((string) ($identifier['identifierType'] ?? '')));
            $value = trim((string) ($identifier['identifier'] ?? ''));
            if ($kind !== '' && $value !== '') {
                $found[$kind] ??= $value;
            }
        }

        return $found;
    }

    /** @param list<array<string, mixed>> $entries */
    private function mainImage(array $entries, string $marketplaceId): ?string
    {
        $images = (array) ($this->forMarketplace($entries, $marketplaceId)['images'] ?? []);
        $best = null;
        foreach ($images as $image) {
            $link = trim((string) ($image['link'] ?? ''));
            if ($link === '') {
                continue;
            }
            if ((string) ($image['variant'] ?? '') === 'MAIN') {
                return $link;
            }
            $best ??= $link;
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private function browseNodes(array $entries, string $marketplaceId): array
    {
        $nodes = [];
        foreach ((array) ($this->forMarketplace($entries, $marketplaceId)['classificationRanks'] ?? []) as $rank) {
            $id = trim((string) ($rank['classificationId'] ?? ''));
            if ($id !== '') {
                $nodes[$id] = $id;
            }
        }

        return array_values($nodes);
    }

    /** @param array<string, mixed> $summary */
    private function isOffered(array $summary): bool
    {
        foreach (['adultProduct', 'autographed', 'memorabilia'] as $irrelevant) {
            unset($summary[$irrelevant]);
        }

        return ($summary['itemClassification'] ?? null) === 'BASE_PRODUCT' || isset($summary['browseClassification']);
    }

    private function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
