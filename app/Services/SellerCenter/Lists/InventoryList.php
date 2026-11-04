<?php

namespace App\Services\SellerCenter\Lists;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use App\Services\Marketplace\StockPolicy;
use App\Services\SellerCenter\Status;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock risk before it becomes a stockout (handoff 07.8).
 *
 * Coverage is the number this screen exists for: available stock divided by the average daily sales
 * of the last fourteen days. Two rules make it honest — it is computed from delivered lines rather
 * than from placed orders, and a product with no sales in the window renders `—` rather than `∞`,
 * because "infinite cover" is a statement about a product nobody is buying.
 */
class InventoryList
{

    public const VIEWS = [
        'all' => ['label' => 'all', 'tone' => 'neutral'],
        'low_stock' => ['label' => 'low_stock', 'tone' => 'high'],
        'out_of_stock' => ['label' => 'out_of_stock', 'tone' => 'critical'],
        'reserved' => ['label' => 'reserved', 'tone' => 'neutral'],
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly StockPolicy $stock,
    ) {
    }

    public function paginate(int $sellerId, Request $request): LengthAwarePaginator
    {
        $view = $this->view($request);
        $threshold = $this->inventory->stockLimitFor($sellerId);

        $query = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('name', 'like', '%' . $search . '%')->orWhere('code', 'like', $search . '%');
            });
        }

        match ($view) {
            'out_of_stock' => $query->where('current_stock', '<=', 0),
            'low_stock' => $query->where('current_stock', '>', 0)->where('current_stock', '<=', max(1, $threshold)),
            default => null,
        };

        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        match ((string) $request->query('sort', '')) {
            'sku' => $query->orderBy('code', $direction),
            'product' => $query->orderBy('name', $direction),
            'available' => $query->orderBy('current_stock', $direction),
            // Worst cover first: this screen is read to decide what to restock.
            default => $query->orderBy('current_stock'),
        };

        return $query->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * The stat strip: five counted figures, no estimates (handoff 07.8).
     *
     * @return array<string, mixed>
     */
    public function summary(int $sellerId): array
    {
        $threshold = $this->inventory->stockLimitFor($sellerId);
        $products = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical']);

        return [
            'threshold' => $threshold,
            'skus' => (clone $products)->count(),
            'units_on_hand' => (int) (clone $products)->sum('current_stock'),
            'running_low' => (clone $products)->where('current_stock', '>', 0)
                ->where('current_stock', '<=', max(1, $threshold))->count(),
            'out_of_stock' => (clone $products)->where('current_stock', '<=', 0)->count(),
            'reserved' => $this->reservedTotal($sellerId),
        ];
    }

    /**
     * Units held by orders that are accepted but not yet delivered.
     *
     * Counted from order lines rather than from a cached column, so it cannot drift away from the
     * orders that actually hold the stock.
     */
    public function reservedTotal(int $sellerId): int
    {
        if (!Schema::hasTable('order_details') || !Schema::hasTable('orders')) {
            return 0;
        }

        return (int) DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->where('order_details.seller_id', $sellerId)
            ->whereIn('orders.order_status', ['confirmed', 'processing', 'out_for_delivery'])
            ->sum('order_details.qty');
    }

    /**
     * Reserved units per product, for the rows on screen only.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function reservedFor(int $sellerId, array $productIds): array
    {
        if ($productIds === [] || !Schema::hasTable('order_details')) {
            return [];
        }

        return DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->where('order_details.seller_id', $sellerId)
            ->whereIn('orders.order_status', ['confirmed', 'processing', 'out_for_delivery'])
            ->whereIn('order_details.product_id', $productIds)
            ->groupBy('order_details.product_id')
            ->pluck(DB::raw('SUM(order_details.qty) as reserved'), 'order_details.product_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Average daily sales over the velocity window, per product.
     *
     * Delivered lines only, matching every other revenue and velocity figure in the platform.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function velocityFor(int $sellerId, array $productIds): array
    {
        if ($productIds === [] || !Schema::hasTable('order_details')) {
            return [];
        }

        $velocityDays = $this->stock->velocityDays();

        return DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered')
            ->where('created_at', '>=', now()->subDays($velocityDays))
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck(DB::raw('SUM(qty) / ' . $velocityDays . ' as daily'), 'product_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * Days of cover, or null when nothing is selling.
     *
     * Null is a real answer and renders `—`. Dividing by zero and printing `∞` would tell a seller
     * their dead stock is their healthiest line.
     */
    public function coverage(int $available, float $dailySales): ?float
    {
        if ($dailySales <= 0.0) {
            return null;
        }

        return $available / $dailySales;
    }

    /** @return array{state: string, tone: string} */
    public function stateFor(int $available, ?float $coverage, int $threshold): array
    {
        if ($available <= 0) {
            return ['state' => 'out_of_stock', 'tone' => Status::CRITICAL];
        }
        $bands = $this->stock->coverBands();

        if ($coverage !== null && $coverage <= $bands['critical']) {
            return ['state' => 'low_stock', 'tone' => Status::CRITICAL];
        }
        if ($coverage !== null && $coverage <= $bands['low']) {
            return ['state' => 'low_stock', 'tone' => Status::HIGH];
        }
        if ($available <= max(1, $threshold)) {
            return ['state' => 'low_stock', 'tone' => Status::HIGH];
        }

        return ['state' => 'healthy', 'tone' => Status::GOOD];
    }

    /** The movement ledger. Never recompute a balance here — render `balance_after` (handoff 07.9). */
    public function movements(int $sellerId, Request $request): LengthAwarePaginator
    {
        return $this->inventory->recent(
            productId: $request->query('product_id') ? (int) $request->query('product_id') : null,
            type: $request->query('type') ?: null,
            perPage: $this->pageSize($request),
            sellerId: $sellerId,
        );
    }

    /** Movement types come from the server, never a hard-coded client list (handoff 07.9). */
    public function movementTypes(): Collection
    {
        return collect([
            StockMovement::TYPE_ADJUSTMENT, StockMovement::TYPE_RECEIPT,
            StockMovement::TYPE_SALE, StockMovement::TYPE_RETURN, StockMovement::TYPE_TRANSFER,
        ]);
    }

    public function filterFields(): array
    {
        return [
            'state' => ['label' => 'state', 'type' => 'enum', 'group' => 'inventory', 'options' => [
                ['value' => 'low_stock', 'label' => translate('low_stock')],
                ['value' => 'out_of_stock', 'label' => translate('out_of_stock')],
            ]],
        ];
    }

    public function view(Request $request): string
    {
        $view = (string) $request->query('view', 'all');

        return array_key_exists($view, self::VIEWS) ? $view : 'all';
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
