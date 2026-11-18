<?php

namespace App\Services\Storefront;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a storefront that is about to open holds in the rows that its money reads (GCC-05, slice 5), for the readiness report: the products that ship free because
 * no cost was typed, the products of sellers (unsupported there until sellers have markets), the coupons typed for it, and the coupons and the shipping row that
 * would make the money wrong. Read-only. A figure is null where its table or column does not exist yet, so the report says nothing rather than something wrong.
 */
final class StorefrontLaunchChecks
{
    /**
     * @return array{free_shipping_products: ?int, seller_products: ?int, live_coupons: ?int, coupons: ?int}
     */
    public function of(string $country): array
    {
        $coupons = $this->couponsOf(country: $country);

        return [
            'free_shipping_products' => $this->productsOf(country: $country, columns: ['product_type', 'status', 'shipping_cost'], narrow: static fn (Builder $query): Builder => $query
                ->where('product_type', 'physical')->where('status', 1)->where(static fn (Builder $inner): Builder => $inner->whereNull('shipping_cost')->orWhere('shipping_cost', 0))),
            'seller_products' => $this->productsOf(country: $country, columns: ['added_by'], narrow: static fn (Builder $query): Builder => $query->where('added_by', 'seller')),
            'live_coupons' => $coupons === null ? null : $this->liveCouponsOf(country: $country),
            'coupons' => $coupons,
        ];
    }

    /** Whether the coupons migration has run, which a storefront other than the default one needs before it can meet a coupon at all. */
    public function couponsTrackStorefront(): bool
    {
        return Schema::hasTable('coupons') && Schema::hasColumn('coupons', 'storefront_country');
    }

    /** Coupons whose storefront is not a configured one: no storefront ever meets them. Null where the column does not exist. */
    public function couponsOfAnUnknownStorefront(): ?int
    {
        if (!$this->couponsTrackStorefront()) {
            return null;
        }

        return DB::table('coupons')->whereNotNull('storefront_country')->whereNotIn('storefront_country', StorefrontResolver::fromConfig()->configuredCountries())->count();
    }

    /** Coupons that carry a storefront, whichever it is. Null where the column does not exist. */
    public function couponsWithAStorefront(): ?int
    {
        return $this->couponsTrackStorefront() ? DB::table('coupons')->whereNotNull('storefront_country')->count() : null;
    }

    /** Whether the row that holds the shop's shipping type exists. Null while the table does not. */
    public function inHouseShippingTypeExists(): ?bool
    {
        return Schema::hasTable('shipping_types') ? DB::table('shipping_types')->where('seller_id', 0)->exists() : null;
    }

    /**
     * @param list<string> $columns the columns the count reads besides the country
     * @param callable(Builder): Builder $narrow
     */
    private function productsOf(string $country, array $columns, callable $narrow): ?int
    {
        if (!Schema::hasTable('products') || !Schema::hasColumns('products', [StorefrontCatalog::COLUMN, ...$columns])) {
            return null;
        }

        return $narrow(DB::table('products')->where(StorefrontCatalog::COLUMN, $country))->count();
    }

    private function couponsOf(string $country): ?int
    {
        return $this->couponsTrackStorefront() ? DB::table('coupons')->where('storefront_country', $country)->count() : null;
    }

    private function liveCouponsOf(string $country): int
    {
        $today = now()->toDateString();

        return DB::table('coupons')->where('storefront_country', $country)->where('status', 1)->where('start_date', '<=', $today)->where('expire_date', '>=', $today)->count();
    }
}
