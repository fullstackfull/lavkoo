<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteListing;
use Carbon\CarbonImmutable;

/**
 * Turns Product V2 records into listings. A Trendyol product is a CONTENT (one product page) holding VARIANTS; each variant carries the barcode that every stock and
 * price call is keyed by, so the barcode is the listing key. Approval state is not a field of the V2 lists: it is which endpoint answered, so records from the
 * unapproved endpoint are `pending_approval` or `rejected` and records from the approved endpoint are live contents.
 */
class TrendyolListingMapper
{
    /**
     * @param array<string, mixed> $content one element of `content[]` from `/products/approved`
     * @return list<RemoteListing>
     */
    public function approved(array $content, SalesChannelAccount $account): array
    {
        $listings = [];
        foreach ((array) ($content['variants'] ?? []) as $variant) {
            $barcode = (string) ($variant['barcode'] ?? '');
            if ($barcode === '') {
                continue;
            }
            $price = $variant['price']['salePrice'] ?? null;
            $quantity = $variant['stock']['quantity'] ?? null;

            $listings[] = new RemoteListing(
                listingKey: $barcode,
                sku: $this->nullable($variant['stockCode'] ?? null),
                externalId: $this->nullable($content['contentId'] ?? null),
                barcode: $barcode,
                parentId: $this->nullable($content['productMainId'] ?? null),
                title: (string) ($content['title'] ?? ''),
                fulfillmentMode: null,
                status: $this->approvedStatus($variant),
                price: $price === null ? null : Money::of((string) $price),
                currency: $account->currency,
                quantity: $quantity === null ? null : (int) $quantity,
                updatedAt: $this->instant($variant['sellerModifiedDate'] ?? $content['lastModifiedDate'] ?? null),
                snapshot: array_filter([
                    'state' => 'approved',
                    'variant_id' => $variant['variantId'] ?? null,
                    'list_price' => $variant['price']['listPrice'] ?? null,
                    'price_seen_by_customer' => $variant['price']['priceSeenByCustomer'] ?? null,
                    'vat_rate' => $variant['vatRate'] ?? null,
                    'brand' => $content['brand']['name'] ?? null,
                    'category_id' => $content['category']['id'] ?? null,
                    'category' => $content['category']['name'] ?? null,
                    'lock_reason' => $variant['lockReason'] ?? null,
                    'on_sale' => $variant['onSale'] ?? null,
                    'locked' => $variant['locked'] ?? null,
                    'archived' => $variant['archived'] ?? null,
                    'blacklisted' => $variant['blacklisted'] ?? null,
                    'product_url' => $variant['productUrl'] ?? null,
                    'channels' => $variant['channels'] ?? null,
                    'commission_rate' => $variant['commission'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== []),
            );
        }

        return $listings;
    }

    /** @param array<string, mixed> $product one element of `content[]` from `/products/unapproved` */
    public function unapproved(array $product, SalesChannelAccount $account): ?RemoteListing
    {
        $barcode = (string) ($product['barcode'] ?? '');
        if ($barcode === '') {
            return null;
        }

        return new RemoteListing(
            listingKey: $barcode,
            sku: $this->nullable($product['stockCode'] ?? null),
            externalId: null,
            barcode: $barcode,
            parentId: $this->nullable($product['productMainId'] ?? null),
            title: (string) ($product['title'] ?? ''),
            fulfillmentMode: null,
            status: ($product['status'] ?? '') === 'rejected' ? 'rejected' : 'pending_approval',
            price: isset($product['salePrice']) ? Money::of((string) $product['salePrice']) : null,
            currency: $account->currency,
            quantity: isset($product['quantity']) ? (int) $product['quantity'] : null,
            updatedAt: $this->instant($product['lastUpdateDate'] ?? null),
            snapshot: array_filter([
                'state' => 'unapproved',
                'list_price' => $product['listPrice'] ?? null,
                'vat_rate' => $product['vatRate'] ?? null,
                'brand' => $product['brand']['name'] ?? null,
                'category_id' => $product['category']['id'] ?? null,
                'reject_reasons' => array_map(static fn (array $reason): string => (string) ($reason['rejectReason'] ?? ''), array_values(array_filter((array) ($product['rejectReasonDetails'] ?? []), 'is_array'))),
            ], static fn ($value): bool => $value !== null && $value !== []),
        );
    }

    /** @param array<string, mixed> $variant */
    private function approvedStatus(array $variant): string
    {
        return match (true) {
            ($variant['blacklisted'] ?? false) === true => 'blacklisted',
            ($variant['archived'] ?? false) === true => 'archived',
            ($variant['locked'] ?? false) === true => 'locked',
            ($variant['onSale'] ?? false) === true => 'on_sale',
            default => 'not_on_sale',
        };
    }

    private function instant(mixed $milliseconds): ?CarbonImmutable
    {
        return is_numeric($milliseconds) ? CarbonImmutable::createFromTimestampMsUTC((int) $milliseconds) : null;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
