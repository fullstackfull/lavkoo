<?php

namespace App\Services\Reports;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Seller;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\Marketplace\SellerMarketTotals;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers behind a seller's reports, computed once for whoever asks.
 *
 * The vendor panel grew these inline, twice over, against `auth('seller')` — which is why the
 * mobile API could not reuse a line of it. Everything here takes the seller id as an argument, so
 * the panel, the API and an export all read the same figures. That matters more than it sounds: a
 * seller who sees one revenue figure in the app and another in the panel trusts neither.
 *
 * Ownership is a WHERE on every query, never a filter over a wider result set. So is the market: every query below goes through
 * SellerMarketScope, so a seller granted some of the platform's markets gets a report, an export and a stock list of those markets
 * and no other — the same bound the order list and the product list carry, read from the same grant rows (K-09). A caller with no
 * grant, which is the vendor panel and every seller while the switch is off, is narrowed by nothing and reads what it always read.
 */
class SellerReportService
{
    /** The column an order records its currency in, written by the storefront snapshot. */
    private const CURRENCY_COLUMN = 'currency_code';

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

    public function __construct(
        private readonly SellerMarketScope $markets,
        private readonly SellerMarketTotals $totals,
        private readonly VendorLedger $ledger,
    ) {
    }

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
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * The product report: what is listed, what sold, and what it earned.
     *
     * @return array{counts: array<string, int>, totals: array<string, float>, top_products: Collection<int, OrderDetail>, chart: array<string, float|int>, chart_labels: array<int, string>}
     */
    public function productReport(int|string $sellerId, ReportWindow $window, ?string $search = null): array
    {
        $sales = $this->deliveredLinesOfProducts($sellerId, $window)->first();

        $chartRows = $this->scopedProducts($sellerId, $window)->get(['created_at']);

        return [
            'counts' => [
                'active' => $this->countProducts($sellerId, $window, self::PRODUCT_APPROVED),
                'pending' => $this->countProducts($sellerId, $window, self::PRODUCT_PENDING),
                'rejected' => $this->countProducts($sellerId, $window, self::PRODUCT_DENIED),
            ],
            'totals' => $this->soldFigures($sales),
            'top_products' => $this->topProducts($sellerId),
            // Counted, not summed: this chart is how many products were listed over the period.
            'chart' => $window->series(
                rows: $chartRows->map(fn ($row) => ['created_at' => $row->created_at, 'listed' => 1]),
                valueKey: 'listed',
            ),
            'chart_labels' => $window->seriesLabels(),
        ];
    }

    /**
     * The product report's money, kept apart by the currency the sale was taken in (K-04.2).
     *
     * `totals.sold_amount` was the sum of every delivered line of the period whatever currency its order was in — 1200 for a
     * thousand riyals plus two hundred dirhams — and `discount_given` the same, 10 for 7 riyals plus 3 dirhams. The controller's
     * own docblock said "no screen here adds two currencies" while this one did, which is the kind of committed assertion a
     * single request disproves.
     *
     * The quantity is **not** refused: a unit sold in Riyadh and a unit sold in Dubai are two units, which is the same
     * distinction `SellerMarketTotals::counts()` draws. Only the two money figures are withheld.
     *
     * @return array{by_currency: list<array<string, mixed>>, combined: array<string, float|int>|null, refused_because: string|null, sold_quantity: float}
     */
    public function productMoneyByCurrency(int|string $sellerId, ReportWindow $window): array
    {
        $byCurrency = $this->productSalesByCurrency($sellerId, $window);
        $money = $this->totals->money($byCurrency);

        return $money + [
            'sold_quantity' => round(array_sum(array_column($byCurrency, 'sold_quantity')), 4),
        ];
    }

    /**
     * What the delivered lines of the period sold, grouped by the currency of the order each line belongs to.
     *
     * The currency lives on the order and not on the line, so the orders are joined in; a database that has not run the
     * storefront snapshot has no such column and therefore one currency by definition, which is the same reading
     * `currenciesInWindow()` takes for the order report.
     *
     * @return array<string, array{sold_quantity: float, sold_amount: float, discount_given: float}>
     */
    private function productSalesByCurrency(int|string $sellerId, ReportWindow $window): array
    {
        $base = (string) $this->ledger->baseCurrency();
        $sales = $this->deliveredLinesOfProducts($sellerId, $window);

        if (!Schema::hasColumn('orders', self::CURRENCY_COLUMN)) {
            $row = $sales->first();

            return [$base => $this->soldFigures($row)];
        }

        $rows = $sales
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->groupBy('orders.' . self::CURRENCY_COLUMN)
            ->addSelect('orders.' . self::CURRENCY_COLUMN . ' as sold_currency')
            ->get();

        $byCurrency = [];

        foreach ($rows as $row) {
            $code = is_string($row->sold_currency) && trim($row->sold_currency) !== '' ? trim($row->sold_currency) : $base;
            $figures = $this->soldFigures($row);

            foreach ($figures as $name => $figure) {
                $byCurrency[$code][$name] = round((float) ($byCurrency[$code][$name] ?? 0) + $figure, 4);
            }
        }

        ksort($byCurrency);

        return $byCurrency === [] ? [$base => $this->soldFigures(null)] : $byCurrency;
    }

    /**
     * The delivered lines of this seller's products in the period, as one query the two readers below both select from.
     */
    private function deliveredLinesOfProducts(int|string $sellerId, ReportWindow $window): Builder
    {
        return $this->scopedProducts($sellerId, $window)
            ->join('order_details', 'order_details.product_id', '=', 'products.id')
            ->where('order_details.delivery_status', self::DELIVERED)
            ->selectRaw('SUM(order_details.qty) as sold_quantity')
            ->selectRaw('SUM(order_details.qty * order_details.price) as sold_amount')
            ->selectRaw('SUM(order_details.discount) as discount_given');
    }

    /**
     * @return array{sold_quantity: float, sold_amount: float, discount_given: float}
     */
    private function soldFigures(?object $row): array
    {
        return [
            'sold_quantity' => (float) ($row->sold_quantity ?? 0),
            'sold_amount' => (float) ($row->sold_amount ?? 0),
            'discount_given' => (float) ($row->discount_given ?? 0),
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
            ->latest('created_at')
            ->latest('id');
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

        return $this->markets->narrowToTheWorkingMarket(Product::query()
            ->where(['product_type' => 'physical', 'added_by' => 'seller', 'user_id' => $sellerId]))
            ->when($categoryId && $categoryId !== 'all',
                fn (Builder $query) => $query->whereJsonContains('category_ids', ['id' => (string) $categoryId]))
            ->when($search, fn (Builder $query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('current_stock', $sort)
            ->latest('id');
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
    public function paymentBreakdown(int|string $sellerId, ReportWindow $window, ?string $currency = null): array
    {
        $delivered = fn () => $this->scopedOrders($sellerId, $window, $currency)->where('order_status', self::DELIVERED);

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
     * The order report's money, kept apart by the currency it is in, and the reason there is no one figure (K-04.2).
     *
     * `amounts.due` used to be the sum of every order in the window whatever currency it was recorded in — riyals plus dirhams
     * plus dinars, printed as one number with no currency anywhere in the answer. A figure like that is not money: it is a number
     * an operator reads as money and acts on. So the combined figure is withheld the moment a second currency is present, exactly
     * as the statement withholds its balance and the stock screen its value, and each currency keeps its own real figures.
     *
     * While the window holds one currency — every seller of one market, and every seller at all until a storefront is opened —
     * `refused_because` is null and the caller publishes the combined figures it always published, byte for byte.
     *
     * @return array{by_currency: list<array<string, mixed>>, combined: array<string, float|int>|null, refused_because: string|null, chart_by_currency: list<array<string, mixed>>}
     */
    public function orderMoneyByCurrency(int|string $sellerId, ReportWindow $window): array
    {
        $byCurrency = [];
        $charts = [];

        foreach ($this->currenciesInWindow($sellerId, $window) as $currency) {
            $byCurrency[$currency] = $this->moneyIn($sellerId, $window, $currency);
            $charts[$currency] = array_values($this->chartIn($sellerId, $window, $currency));
        }

        $money = $this->totals->money($byCurrency);

        return $money + ['chart_by_currency' => $money['refused_because'] === null ? [] : array_map(
            static fn (string $currency, array $values): array => ['currency_code' => $currency, 'values' => $values],
            array_keys($charts),
            array_values($charts),
        )];
    }

    /**
     * Every currency the window's orders are recorded in, in a settled order.
     *
     * An order recorded before the storefront snapshot names no currency and is read as the store's base currency, as the ledger
     * reads an entry that names none — so the orders of before do not become a currency of their own and do not, by themselves,
     * withhold a figure that was always right. A database whose orders have no currency column has one currency by definition.
     *
     * @return list<string>
     */
    private function currenciesInWindow(int|string $sellerId, ReportWindow $window): array
    {
        $base = (string) $this->ledger->baseCurrency();

        if (!Schema::hasColumn('orders', self::CURRENCY_COLUMN)) {
            return [$base];
        }

        $codes = $this->scopedOrders($sellerId, $window)
            ->distinct()
            ->pluck(self::CURRENCY_COLUMN)
            ->map(static fn ($code): string => is_string($code) && trim($code) !== '' ? trim($code) : '')
            ->map(static fn (string $code): string => $code === '' ? $base : $code)
            ->unique()
            ->values()
            ->all();

        sort($codes);

        return $codes === [] ? [$base] : $codes;
    }

    /**
     * The report's money figures for the orders of one currency: the two amounts and the five payment buckets, named exactly as
     * the combined blocks name them so a client reads one shape in both cases.
     *
     * @return array<string, float>
     */
    private function moneyIn(int|string $sellerId, ReportWindow $window, string $currency): array
    {
        $due = $this->scopedOrders($sellerId, $window, $currency)
            ->whereNotIn('order_status', array_merge([self::DELIVERED], self::CANCELED_STATUSES));
        $settled = $this->scopedOrders($sellerId, $window, $currency)->where('order_status', self::DELIVERED);

        return [
            'due' => (float) $due->sum('order_amount'),
            'settled' => (float) $settled->sum('order_amount'),
        ] + $this->paymentBreakdown($sellerId, $window, $currency);
    }

    /** The settled series of one currency, over the same buckets and labels the combined chart uses. */
    private function chartIn(int|string $sellerId, ReportWindow $window, string $currency): array
    {
        return $window->series(
            rows: $this->scopedOrders($sellerId, $window, $currency)
                ->where('order_status', self::DELIVERED)
                ->get(['created_at', 'order_amount']),
            valueKey: 'order_amount',
        );
    }

    /**
     * Keeps the orders recorded in one currency. The store's base currency also keeps the orders that name none, for the reason
     * `currenciesInWindow()` reads them as the base currency: they are in the platform's own money.
     */
    private function inCurrency(Builder $orders, string $currency): Builder
    {
        if (!Schema::hasColumn('orders', self::CURRENCY_COLUMN)) {
            return $orders;
        }

        $column = $orders->qualifyColumn(self::CURRENCY_COLUMN);

        return $currency === (string) $this->ledger->baseCurrency()
            ? $orders->where(fn (Builder $inner): Builder => $inner->whereNull($column)->orWhere($column, $currency))
            : $orders->where($column, $currency);
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
            ->whereHas('product', fn (Builder $query) => $this->markets->narrowQuery(
                $query->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ))
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

    /**
     * The seller's orders in the period, held to the markets the request was granted.
     *
     * The bound is the order list's own bound, asked of the same service, so the report and the list cannot answer different sets
     * of orders to the same token — which is what made the report a way around the list. `$currency` narrows further to the
     * orders recorded in one currency, which is how the per-currency figures are produced.
     */
    private function scopedOrders(int|string $sellerId, ReportWindow $window, ?string $currency = null): Builder
    {
        $orders = $this->markets->narrowOrdersToTheWorkingMarket(
            $window->apply(Order::where(['seller_is' => 'seller', 'seller_id' => $sellerId]), 'orders.created_at')
        );

        return $currency === null ? $orders : $this->inCurrency($orders, $currency);
    }

    private function scopedProducts(int|string $sellerId, ReportWindow $window): Builder
    {
        return $this->markets->narrowToTheWorkingMarket(
            $window->apply(Product::where(['added_by' => 'seller', 'user_id' => $sellerId]), 'products.created_at')
        );
    }
}
