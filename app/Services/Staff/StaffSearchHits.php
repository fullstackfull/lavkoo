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
 *
 * A RECENT search is narrowed by the same three readings ({@see keepRecent()}). A recent search is not a query at all -- it is a row of
 * `recent_searches` holding what the employee's browser posted when they last clicked a hit -- so a hit saved BEFORE the employee was
 * restricted would otherwise keep naming, titling and linking another market's order for as long as the row lives (G-06, decision 17).
 * Filtering it can only ever narrow: a recent hit is kept when the search that produced it would produce it today, and dropped otherwise.
 */
final class StaffSearchHits
{
    /**
     * The hit kinds that belong to a market, by the model whose rule decides them. The same three `keep()` narrows, and the only three a
     * recent search can be judged by: everything else the search finds belongs to no market.
     *
     * @var array<string, class-string>
     */
    private const MARKET_OWNING_KINDS = [
        'products' => Product::class,
        'orders' => Order::class,
        'refund_requests' => RefundRequest::class,
    ];

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

    /**
     * The recent searches a restricted employee may still be shown, in the grouped-by-type shape both search controllers hand their view.
     *
     * Only the three kinds that belong to a market are judged -- a product, an order and a refund request -- because they are the three
     * `keep()` judges and the rule must not be two rules. Every other kind (a customer, a vendor, a coupon, a page) is handed back
     * untouched: it belongs to no market, and the employee's ROLE has already decided it in `AdvancedSearchPermissionFilter`.
     *
     * A hit is judged by the ROW ITS ADDRESS NAMES, not by its stored title, because the title is a value the browser posted and the
     * address is what the employee would open. A hit of one of the three kinds whose address names no id is a LIST page -- the same link
     * for every employee, and the screen behind it is scoped by its own slice -- so it is kept. One whose address names something that is
     * not an id is dropped: a stored value nobody can resolve is not something to hand back.
     *
     * @param array<string, array<int, mixed>> $grouped
     * @param list<string>|null $countries the employee's countries, or null when they are not restricted
     * @return array<string, array<int, mixed>>
     */
    public function keepRecent(array $grouped, ?array $countries): array
    {
        if ($countries === null) {
            return $grouped;
        }

        foreach ($grouped as $type => $items) {
            if (!isset(self::MARKET_OWNING_KINDS[$type])) {
                continue;
            }

            $kept = array_values(array_filter(
                $items,
                fn (mixed $item): bool => $this->recentHitIsVisible(type: (string) $type, item: $item, countries: $countries),
            ));

            if ($kept === []) {
                unset($grouped[$type]);

                continue;
            }

            $grouped[$type] = $kept;
        }

        return $grouped;
    }

    private function recentHitIsVisible(string $type, mixed $item, array $countries): bool
    {
        $uri = (string) (((array) $item)['uri'] ?? '');
        $id = $this->idNamedBy(uri: $uri);

        if ($id === null) {
            // A list page of a scoped screen, which is the same link for every employee. The one shape this keeps on purpose.
            return !preg_match('#/\d+$#', rtrim($uri, '/')) && !str_contains($uri, 'id=');
        }

        $visible = match (self::MARKET_OWNING_KINDS[$type]) {
            Order::class => $this->visibleOrders(ids: [$id], countries: $countries),
            RefundRequest::class => $this->visibleRefunds(ids: [$id], countries: $countries),
            default => $this->productsOf(ids: [$id], countries: $countries),
        };

        return $visible !== [];
    }

    /** The id the address of a hit names, as `AdvanceSearch` writes it: the last segment of the path, or the `id` of its query. */
    private function idNamedBy(string $uri): ?int
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');

        parse_str($query, $parameters);
        $segments = explode('/', rtrim($path, '/'));
        $last = $parameters['id'] ?? end($segments);

        return is_string($last) && preg_match('/^[1-9]\d*$/', $last) === 1 ? (int) $last : null;
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
