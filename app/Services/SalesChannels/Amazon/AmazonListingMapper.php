<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteListing;
use Carbon\CarbonImmutable;

/**
 * Turns a Listings Items API 2021-08-01 `Item` into a listing row.
 *
 * `fulfillmentAvailability` says who fulfils it: the channel code DEFAULT is the merchant (FBM), AMAZON_NA / AMAZON_EU / AMAZON_JP is Amazon (FBA), and a SKU can
 * carry both. The quantity read here is the FBM quantity only; Amazon does not report one for FBA, whose stock comes from the FBA Inventory API.
 */
class AmazonListingMapper
{
    /** @param array<string, mixed> $item */
    public function map(array $item, string $marketplaceId): RemoteListing
    {
        $sku = (string) $item['sku'];
        $summary = $this->forMarketplace((array) ($item['summaries'] ?? []), $marketplaceId);
        $offer = $this->offer((array) ($item['offers'] ?? []), $marketplaceId);
        $availability = (array) ($item['fulfillmentAvailability'] ?? []);

        $merchant = null;
        $amazon = false;
        foreach ($availability as $entry) {
            $code = (string) ($entry['fulfillmentChannelCode'] ?? '');
            if ($code === 'DEFAULT') {
                $merchant = (int) ($entry['quantity'] ?? 0);
            } elseif (str_starts_with($code, 'AMAZON_')) {
                $amazon = true;
            }
        }

        $mode = match (true) {
            $merchant !== null && $amazon => 'both',
            $amazon => 'fba',
            $merchant !== null => 'fbm',
            default => null,
        };

        $relationships = [];
        foreach ((array) ($item['relationships'] ?? []) as $group) {
            if (($group['marketplaceId'] ?? null) === $marketplaceId || ($group['marketplaceId'] ?? null) === null) {
                $relationships = (array) ($group['relationships'] ?? []);
            }
        }
        $parent = null;
        foreach ($relationships as $relationship) {
            if (($relationship['type'] ?? null) === 'VARIATION' && !empty($relationship['parentSkus'])) {
                $parent = (string) $relationship['parentSkus'][0];
            }
        }

        return new RemoteListing(
            listingKey: $sku,
            sku: $sku,
            externalId: isset($summary['asin']) ? (string) $summary['asin'] : null,
            barcode: null,
            parentId: $parent,
            title: (string) ($summary['itemName'] ?? $sku),
            fulfillmentMode: $mode,
            status: isset($summary['status']) ? implode(',', (array) $summary['status']) : null,
            price: isset($offer['price']['amount']) ? Money::of((string) $offer['price']['amount']) : null,
            currency: isset($offer['price']['currencyCode']) ? strtoupper((string) $offer['price']['currencyCode']) : null,
            quantity: $merchant,
            updatedAt: isset($summary['lastUpdatedDate']) ? CarbonImmutable::parse((string) $summary['lastUpdatedDate']) : null,
            snapshot: array_filter([
                'product_type' => $summary['productType'] ?? null,
                'fn_sku' => $summary['fnSku'] ?? null,
                'condition' => $summary['conditionType'] ?? null,
                'created' => $summary['createdDate'] ?? null,
                'variation_parent' => $parent,
                'issue_count' => isset($item['issues']) ? count((array) $item['issues']) : null,
                'errors' => isset($item['issues']) ? count(array_filter((array) $item['issues'], static fn ($issue): bool => ($issue['severity'] ?? null) === 'ERROR')) : null,
            ], static fn ($value): bool => $value !== null),
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function forMarketplace(array $entries, string $marketplaceId): array
    {
        foreach ($entries as $entry) {
            if (($entry['marketplaceId'] ?? null) === $marketplaceId) {
                return $entry;
            }
        }

        return (array) ($entries[0] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array<string, mixed>
     */
    private function offer(array $offers, string $marketplaceId): array
    {
        foreach ($offers as $offer) {
            if (($offer['marketplaceId'] ?? null) === $marketplaceId && ($offer['offerType'] ?? 'B2C') === 'B2C') {
                return $offer;
            }
        }

        return [];
    }
}
