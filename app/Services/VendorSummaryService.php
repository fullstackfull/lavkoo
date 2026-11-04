<?php

namespace App\Services;

/**
 * A vendor as a shopper may see it.
 *
 * The customer endpoints used to answer with whole Seller models and their whole
 * Shop relation, which meant a guest listing the storefront's vendors also
 * received the seller's private phone and email, their bank name, branch,
 * account number and account holder, and the shop's tax identification number
 * together with a link to the uploaded TIN certificate. None of that belongs to
 * a shopper, and none of it is needed to draw a vendor card.
 *
 * What stays is what a storefront legitimately shows — the store's name and
 * media, how much it sells, what shoppers rated it, whether it is open — plus
 * the handful of commerce facts the published apps parse strictly (a missing
 * `pos_status` or `minimum_order_amount` throws in their model), so trimming the
 * payload can never break a client already in someone's hand.
 */
class VendorSummaryService
{
    /**
     * @param  iterable  $sellers
     * @return array<int, array<string, mixed>>
     */
    public function summarizeMany(iterable $sellers): array
    {
        $summaries = [];
        foreach ($sellers as $seller) {
            $summaries[] = $this->summarize($seller);
        }

        return $summaries;
    }

    public function summarize(object $seller): array
    {
        $shop = data_get($seller, 'shop');

        return [
            'id' => data_get($seller, 'id'),
            'f_name' => data_get($seller, 'f_name'),
            'l_name' => data_get($seller, 'l_name'),
            'status' => data_get($seller, 'status'),
            'image' => data_get($seller, 'image'),
            'image_full_url' => data_get($seller, 'image_full_url'),

            // Trust and range, the reasons a shopper opens a store at all.
            'product_count' => (int)data_get($seller, 'product_count', 0),
            'total_rating' => data_get($seller, 'total_rating', 0),
            'rating_count' => data_get($seller, 'rating_count', 0),
            'review_count' => data_get($seller, 'review_count', 0),
            'average_rating' => data_get($seller, 'average_rating'),
            'positive_review' => data_get($seller, 'positive_review', 0),
            'orders_count' => data_get($seller, 'orders_count', 0),

            // Whether the store can be shopped right now.
            'temporary_close' => (int)data_get($seller, 'temporary_close', 0),
            'is_vacation_mode_now' => (bool)data_get($seller, 'is_vacation_mode_now', false),

            // Commerce terms the shopper is subject to — and which the published
            // apps parse without a null guard, so they are always present.
            'pos_status' => (int)data_get($seller, 'pos_status', 0),
            'minimum_order_amount' => (float)data_get($seller, 'minimum_order_amount', 0),
            'free_delivery_status' => (float)data_get($seller, 'free_delivery_status', 0),
            'free_delivery_over_amount' => (float)data_get($seller, 'free_delivery_over_amount', 0),

            'shop' => $shop ? $this->summarizeShop($shop) : null,
        ];
    }

    /**
     * The storefront half of a shop: what it is called, how it looks and when it
     * is open. Its business address and contact stay — a storefront publishes
     * those — while the tax number, the certificate and the seller's onboarding
     * state do not.
     */
    /**
     * One shop as a shopper may see it.
     *
     * Public because a shop is also presented on its own — a home-page showcase is one store, not
     * a list — and the answer to "what may a customer see about this shop" must be the same
     * wherever it is asked.
     */
    public function summarizeShop(object|array $shop): array
    {
        return [
            'id' => data_get($shop, 'id'),
            'seller_id' => (int)data_get($shop, 'seller_id', 0),
            'author_type' => data_get($shop, 'author_type'),
            'name' => data_get($shop, 'name'),
            'slug' => data_get($shop, 'slug'),
            'address' => data_get($shop, 'address'),
            'contact' => data_get($shop, 'contact'),

            'image' => data_get($shop, 'image'),
            'image_full_url' => data_get($shop, 'image_full_url'),
            'banner' => data_get($shop, 'banner'),
            'banner_full_url' => data_get($shop, 'banner_full_url'),
            'offer_banner' => data_get($shop, 'offer_banner'),
            'offer_banner_full_url' => data_get($shop, 'offer_banner_full_url'),
            'bottom_banner' => data_get($shop, 'bottom_banner'),
            'bottom_banner_full_url' => data_get($shop, 'bottom_banner_full_url'),

            'temporary_close' => data_get($shop, 'temporary_close'),
            'vacation_status' => data_get($shop, 'vacation_status'),
            'vacation_start_date' => data_get($shop, 'vacation_start_date'),
            'vacation_end_date' => data_get($shop, 'vacation_end_date'),
            'vacation_duration_type' => data_get($shop, 'vacation_duration_type'),
            'vacation_note' => data_get($shop, 'vacation_note'),

            // Present only where the caller resolved them; a showcase carries them, a raw Shop
            // row does not, and a zero invented here would read as a verdict nobody gave.
            'average_rating' => data_get($shop, 'average_rating'),
            'review_count' => data_get($shop, 'review_count'),
            'products_count' => data_get($shop, 'products_count'),

            'created_at' => data_get($shop, 'created_at'),
            'updated_at' => data_get($shop, 'updated_at'),
        ];
    }
}
