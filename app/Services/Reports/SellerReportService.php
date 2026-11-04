<?php

namespace App\Services\Reports;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The numbers behind a seller's reports, computed once for whoever asks.
 *
 * The vendor panel grew these inline, twice over, against `auth('seller')` — which is why the
 * mobile API could not reuse a line of it. Everything here takes the seller id as an argument, so
 * the panel, the API and an export all read the same figures. That matters more than it sounds: a
 * seller who sees one revenue figure in the app and another in the panel trusts neither.
 *
 * Ownership is a WHERE on every query, never a filter over a wider result set.
 */
class SellerReportService
{
    /** Orders that are neither finished nor abandoned — money the seller is still owed. */
    private const ONGOING_STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery'];

    /** Orders that will never settle. */
    private const CANCELED_STATUSES = ['canceled', 'failed', 'returned'];

    private const DELIVERED = 'delivered';

    /** Payment methods that arrive as cash rather than through a gateway. */
    private const CASH_METHODS = ['cash', 'cash_on_delivery'];

    private const WALLET_METHOD = 'pay_by_wallet';

    private const OFFLINE_METHOD = 'offline_payment';

    /** `products.request_status`, which the schema stores as a bare integer. */
    private const PRODUCT_PENDING = 0;
    private const PRODUCT_APPROVED = 1;
    private const PRODUCT_DENIED = 2;

    /**
     * The order report: how many orders, in what state, worth how much, paid by what means.
     *
     * @return array{counts: array<string, int>, amounts: array<string, float>, payments: array<string, float>, chart: array<string, float|int>, chart_labels: array<int, string>}
     */
    public function orderReport(int|string $sellerId, ReportWindow $window, ?string $search = null): array
    {
        $counts = [
            'ongoing' => $this->countOrders($sellerId, $window, self::ONGOING_STATUSES),
            'canceled' => $this->countOrders($sellerId, $window, self::CANCELED_STATUSES),
            'delivered' => $this->countOrders($sellerId, $window, [self::DELIVERED]),
        ];
        $counts['total'] = $counts['ongoing'] + $counts['canceled'] + $counts['delivered'];

        // Due is everything not yet resolved either way — an order that was cancelled is not owed.
        $dueQuery = $this->scopedOrders($sellerId, $window)
            ->whereNotIn('order_status', array_merge([self::DELIVERED], self::CANCELED_STATUSES));
        $settledQuery = $this->scopedOrders($sellerId, $window)->where('order_status', self::DELIVERED);

        $chartRows = $this->scopedOrders($sellerId, $window)
            ->where('order_status', self::DELIVERED)
            ->get(['created_at', 'order_amount']);

        return [
            'counts' => $counts,
            'amounts' => [
                'due' => (float) $dueQuery->sum('order_amount'),
                'settled' => (float) $settledQuery->sum('order_amount'),
            ],
            'payments' => $this->paymentBreakdown($sellerId, $window),
            'chart' => $window->series(rows: $chartRows, valueKey: 'order_amount'),
            'chart_labels' => $window->seriesLabels(),
        ];
    }

    /**
     * The orders the report lists, ready to paginate or export.
     *
     * The two per-order sums come from the details rather than the order row, because a discount or
     * a tax recorded per line is the only place the real figure lives.
     */
    public function orderQuery(int|string $sellerId, ReportWindow $window, ?string $search = null): Builder
    {
        return $this->scopedOrders($sellerId, $window)
            ->withSum('details', 'tax')
            ->withSum('details', 'discount')
            ->when($search, fn (Builder $query, string $term) => $query->where('id', 'like', "%{$term}%"))
            ->latest('created_at');
    }

    /**
     * The product report: what is listed, what sold, and what it earned.
     *
     * @return array{counts: array<string, int>, totals: array<string, float>, top_products: Collection<int, OrderDetail>, chart: array<string, float|int>, chart_labels: array<int, string>}
     */
    public function productReport(int|string $sellerId, ReportWindow $window, ?string $search = null): array
    {
        $sales = $this->scopedProducts($sellerId, $window)
            ->join('order_details', 'order_details.product_id', '=', 'products.id')
            ->where('order_details.delivery_status', self::DELIVERED)
            ->selectRaw('SUM(order_details.qty) as sold_quantity')
            ->selectRaw('SUM(order_details.qty * order_details.price) as sold_amount')
            ->selectRaw('SUM(order_details.discount) as discount_given')
            ->first();

        $chartRows = $this->scopedProducts($sellerId, $window)->get(['created_at']);

        return [
            'counts' => [
                'active' => $this->countProducts($sellerId, $window, self::PRODUCT_APPROVED),
                'pending' => $this->countProducts($sellerId, $window, self::PRODUCT_PENDING),
                'rejected' => $this->countProducts($sellerId, $window, self::PRODUCT_DENIED),
            ],
            'totals' => [
                'sold_quantity' => (float) ($sales->sold_quantity ?? 0),
                'sold_amount' => (float) ($sales->sold_amount ?? 0),
                'discount_given' => (float) ($sales->discount_given ?? 0),
            ],
            'top_products' => $this->topProducts($sellerId),
            // Counted, not summed: this chart is how many products were listed over the period.
            'chart' => $window->series(
                rows: $chartRows->map(fn ($row) => ['created_at' => $row->created_at, 'listed' => 1]),
                valueKey: 'listed',
            ),
            'chart_labels' => $window->seriesLabels(),
        ];
    }

    /** The products the report lists, each carrying what it has sold. */
    public function productQuery(int|string $sellerId, ReportWindow $window, ?string $search = null): Builder
    {
        return $this->scopedProducts($sellerId, $window)
            ->with(['orderDetails' => function ($query) {
                $query->select('product_id')
                    ->selectRaw('SUM(qty * price) as total_sold_amount')
                    ->selectRaw('SUM(qty) as product_quantity')
                    ->where('delivery_status', self::DELIVERED)
                    ->groupBy('product_id');
            }])
            ->when($search, fn (Builder $query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->latest('created_at');
    }

    /**
     * The stock report: physical products ordered by how little is left.
     *
     * Not windowed — a stock level is a fact about now, not about a period.
     */
    public function stockQuery(
        int|string $sellerId,
        ?string $search = null,
        string $sort = 'ASC',
        int|string|null $categoryId = null,
    ): Builder {
        $sort = strtoupper($sort) === 'DESC' ? 'DESC' : 'ASC';

        return Product::query()
            ->where(['product_type' => 'physical', 'added_by' => 'seller', 'user_id' => $sellerId])
            ->when($categoryId && $categoryId !== 'all',
                fn (Builder $query) => $query->whereJsonContains('category_ids', ['id' => (string) $categoryId]))
            ->when($search, fn (Builder $query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('current_stock', $sort);
    }

    /**
     * The level at which this seller's stock counts as low.
     *
     * A seller may set their own; zero or unset means they have not, and the platform default
     * applies — the same resolution the panel does.
     */
    public function stockLimitFor(int|string|Seller $seller): int
    {
        $limit = $seller instanceof Seller
            ? (int) $seller->stock_limit
            : (int) Seller::where('id', $seller)->value('stock_limit');

        return $limit > 0 ? $limit : (int) getWebConfig(name: 'stock_limit');
    }

    /** The top-level categories a stock report can be filtered by. */
    public function stockFilterCategories(): Collection
    {
        return Category::where('position', 0)->get(['id', 'name']);
    }

    /**
     * What the seller was paid, and by what means.
     *
     * An order's payment method records how the first payment arrived. Anything added afterwards —
     * an order edited to add an item, and paid for separately — lands in the edit history with its
     * own method, and a returned amount lands there too. Reading only the order row understates
     * every edited order, so the history is folded in and the returns taken back out.
     *
     * @return array<string, float>
     */
    public function paymentBreakdown(int|string $sellerId, ReportWindow $window): array
    {
        $delivered = fn () => $this->scopedOrders($sellerId, $window)->where('order_status', self::DELIVERED);

        $cash = (float) $delivered()->whereIn('payment_method', self::CASH_METHODS)->sum('init_order_amount');
        $wallet = (float) $delivered()->where('payment_method', self::WALLET_METHOD)->sum('init_order_amount');
        $offline = (float) $delivered()->where('payment_method', self::OFFLINE_METHOD)->sum('init_order_amount');
        $digital = (float) $delivered()
            ->whereNotIn('payment_method', array_merge(self::CASH_METHODS, [self::WALLET_METHOD, self::OFFLINE_METHOD]))
            ->sum('init_order_amount');

        $edits = ['cash' => 0.0, 'wallet' => 0.0, 'offline' => 0.0, 'digital' => 0.0];
        $returned = 0.0;

        foreach ($delivered()->with('orderEditHistory')->get() as $order) {
            foreach ($order->orderEditHistory ?? [] as $edit) {
                if ($edit->order_return_payment_status === 'returned') {
                    $returned += (float) $edit->order_return_amount;
                }

                if ($edit->order_due_payment_method === null || $edit->order_due_payment_status !== 'paid') {
                    continue;
                }

                // The edit history names its methods slightly differently from the order row:
                // `wallet` rather than `pay_by_wallet`, and only cash-on-delivery for cash.
                $bucket = match ($edit->order_due_payment_method) {
                    'cash_on_delivery' => 'cash',
                    'wallet' => 'wallet',
                    self::OFFLINE_METHOD => 'offline',
                    default => 'digital',
                };
                $edits[$bucket] += (float) $edit->order_due_amount;
            }
        }

        $cash += $edits['cash'];
        $wallet += $edits['wallet'];
        $offline += $edits['offline'];
        $digital += $edits['digital'];

        return [
            'cash' => $cash,
            'wallet' => $wallet,
            'digital' => $digital,
            'offline' => $offline,
            'returned' => $returned,
            'total' => ($cash + $wallet + $digital + $offline) - $returned,
        ];
    }

    /**
     * The totals a printed order report carries at its foot.
     *
     * Two of them cannot be summed from a column: shipping the seller waived under a free-shipping
     * offer was never charged, and a delivery incentive is only owed on the seller's own deliveries.
     *
     * @param  Collection<int, \App\Models\Order>  $orders
     * @return array<string, float|int>
     */
    public function orderTotals(Collection $orders): array
    {
        $deliveryCharge = 0.0;
        $deliverymanIncentive = 0.0;

        foreach ($orders as $order) {
            $deliveryCharge += (float) $order->shipping_cost
                - ($order->extra_discount_type === 'free_shipping_over_order_amount' ? (float) $order->extra_discount : 0);
            $deliverymanIncentive += ($order->delivery_type === 'self_delivery' && $order->delivery_man_id)
                ? (float) $order->deliveryman_charge
                : 0;
        }

        return [
            'total_orders' => $orders->count(),
            'total_order_amount' => (float) $orders->sum('order_amount'),
            'total_product_discount' => (float) $orders->sum('details_sum_discount'),
            'total_coupon_discount' => (float) $orders->sum('discount_amount'),
            'total_referral_discount' => (float) $orders->sum('refer_and_earn_discount'),
            'tota_order_due_amount' => (float) $orders->sum('edit_due_amount'),
            'total_order_return_amount' => (float) $orders->sum('edit_return_amount'),
            'total_tax' => (float) $orders->sum('details_sum_tax'),
            'total_order_commission' => (float) $orders->sum('admin_commission'),
            'total_delivery_charge' => $deliveryCharge,
            'total_deliveryman_incentive' => $deliverymanIncentive,
        ];
    }

    /** @return Collection<int, OrderDetail> */
    private function topProducts(int|string $sellerId, int $limit = 5): Collection
    {
        return OrderDetail::with('product:id,name,thumbnail')
            ->selectRaw('product_id, SUM(qty * price) as total_amount, SUM(qty) as total_quantity')
            ->where('delivery_status', self::DELIVERED)
            ->whereHas('product', fn (Builder $query) => $query->where(['added_by' => 'seller', 'user_id' => $sellerId]))
            ->groupBy('product_id')
            ->orderByDesc('total_amount')
            ->take($limit)
            ->get();
    }

    private function countOrders(int|string $sellerId, ReportWindow $window, array $statuses): int
    {
        return $this->scopedOrders($sellerId, $window)->whereIn('order_status', $statuses)->count();
    }

    private function countProducts(int|string $sellerId, ReportWindow $window, int $requestStatus): int
    {
        return $this->scopedProducts($sellerId, $window)->where('request_status', $requestStatus)->count();
    }

    private function scopedOrders(int|string $sellerId, ReportWindow $window): Builder
    {
        return $window->apply(
            Order::where(['seller_is' => 'seller', 'seller_id' => $sellerId]),
            'orders.created_at',
        );
    }

    private function scopedProducts(int|string $sellerId, ReportWindow $window): Builder
    {
        return $window->apply(
            Product::where(['added_by' => 'seller', 'user_id' => $sellerId]),
            'products.created_at',
        );
    }
}
