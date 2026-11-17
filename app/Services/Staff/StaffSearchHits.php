<?php

namespace App\Services\Staff;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the search of the admin header may hand a restricted employee (GCC-14c, slice 3): the orders, refund requests and products of their countries, and nothing that names another
 * country's.
 *
 * The search reads whole tables once and keeps them in the cache every employee shares, so it cannot narrow the query: the first employee to search would decide what the others
 * find. It narrows what it hands back instead, with one statement for the hits of a kind, by the same rule as the scopes of the models (StaffOrderVisibility).
 */
final class StaffSearchHits
{
    /**
     * @param Collection<int, Model> $hits the models a search found in one table
     * @param list<string>|null $countries the employee's countries, or null when they are not restricted
     * @return Collection<int, Model>
     */
    public function keep(Collection $hits, string $model, ?array $countries): Collection
    {
        if ($countries === null || $hits->isEmpty()) {
            return $hits;
        }

        $visible = match ($model) {
            Order::class => $this->visibleOrders(ids: $hits->pluck('id')->all(), countries: $countries),
            RefundRequest::class => $this->visibleRefunds(ids: $hits->pluck('id')->all(), countries: $countries),
            Product::class => $this->productsOf(ids: $hits->pluck('id')->all(), countries: $countries),
            default => null,
        };

        return $visible === null ? $hits : $hits->filter(static fn (Model $hit): bool => in_array((int) $hit->getKey(), $visible, true))->values();
    }

    /** @return list<int> */
    private function visibleOrders(array $ids, array $countries): array
    {
        $orders = DB::table('orders')->whereIn('orders.id', $ids);
        StaffOrderVisibility::constrainOrders(query: $orders, orderIdColumn: 'orders.id', countries: $countries);

        return array_map('intval', $orders->pluck('orders.id')->all());
    }

    /** @return list<int> */
    private function visibleRefunds(array $ids, array $countries): array
    {
        $refunds = DB::table('refund_requests')->whereIn('refund_requests.id', $ids);
        StaffOrderVisibility::constrainRefunds(query: $refunds, orderLineIdColumn: 'refund_requests.order_details_id', countries: $countries);

        return array_map('intval', $refunds->pluck('refund_requests.id')->all());
    }

    /** @return list<int> */
    private function productsOf(array $ids, array $countries): array
    {
        return array_map('intval', DB::table('products')->whereIn('id', $ids)->whereIn(StorefrontCatalog::COLUMN, $countries)->pluck('id')->all());
    }
}
