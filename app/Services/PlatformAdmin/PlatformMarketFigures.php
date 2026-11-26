<?php

namespace App\Services\PlatformAdmin;

use App\Models\InventoryReservation;
use App\Services\Marketplace\SettlementTotals;
use App\Services\Marketplace\SlaService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform reports per market, counted in the database. Every figure is a grouped aggregate over a bounded range; nothing is loaded
 * into PHP row by row, and nothing here goes through the Product model, whose scopes narrow a query to the storefront a request asks for.
 *
 * A market comes from the country of a product (`products.storefront_country`), because an order carries none of its own:
 *
 *  - an order is in a market when it has at least one line, every line is a product that still exists, and all of them are of one configured
 *    market. Any other order has no market and is counted apart, never as Saudi;
 *  - a refund request is in the market of the product it was made for;
 *  - a product is in its own market, and counts as stock only while it is live: physical, active, approved, and its seller approved;
 *  - a stock hold and a stock pool are in the market of the product they are for.
 *
 * While the column is missing (the products migration of the storefront has not run) every product belongs to the default storefront, which is
 * what the storefront code itself assumes, and the answer says so.
 *
 * One instance serves a whole request, so that the overview and the alerts ask the schema once.
 */
#[Scoped]
final class PlatformMarketFigures
{
    /**
     * How an order is put in a market, as every answer that reports orders names it. One constant and not a literal per answer, because the overview's
     * count and the drill-down's list are the same rule read twice (`ordersWithTheirMarket()`): if one ever says something different from the other,
     * the two answers disagree about which orders a market has and nothing says which is right.
     */
    public const ORDER_ATTRIBUTION = ['rule' => 'all_lines_one_market', 'via' => 'order_details.product_id > products.storefront_country'];

    /** How a RETURN is put in a market: the market of the product it was made for, which is what the counted refund figures group by. */
    public const RETURN_ATTRIBUTION = ['rule' => 'product_market', 'via' => 'refund_requests.product_id > products.storefront_country'];

    /** How a PRODUCT, and so the stock it holds, is put in a market: its own recorded market. */
    public const PRODUCT_ATTRIBUTION = ['rule' => 'product_market', 'via' => 'products.storefront_country'];

    private const COUNTRY_COLUMN = 'storefront_country';

    private const ENTITY_COLUMN = 'legal_entity_id';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * What `orders.payment_status` says of an order whose money has actually been received. The order column and not a gateway's own verdict
     * (`App\Services\Payments\GatewayTransactionState`): one is what this platform recorded of the sale, the other is what a provider said of one attempt.
     */
    private const PAID = 'paid';

    /** What `orders.tax_model` says of a sale whose price already held its tax (`App\Services\Tax\IncludedTax`); the other value is `exclude`. */
    private const TAX_INCLUDED = 'include';


    private ?bool $countryColumn = null;

    /** @var array<string, bool> whether the orders table carries a column, asked once per column and per request */
    private array $orderColumns = [];

    /** @var array<string, bool> whether one of the dormant inventory tables exists, asked once per table and per request */
    private array $inventoryTables = [];

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformAdminSettings $settings,
        private readonly SettlementTotals $settlements,
    ) {
    }

    /**
     * What the platform settled in the window, by the company that owes it and the currency it is drawn in (E-17).
     *
     * Read through `App\Services\Marketplace\SettlementTotals` and not here, because the settlement table names the shop it pays in a
     * column this area of the application may not mention at all -- the same indirection, and for the same reason, as the shop issue counts.
     *
     * @return list<array{entity: ?int, currency: ?string, status: string, settlements: int, net: ?string}>
     */
    public function settlements(CarbonInterface $from, CarbonInterface $before): array
    {
        return $this->settlements->byCompanyAndCurrency(from: $from, before: $before);
    }

    /** Whether a settlement of this installation says which currency it is drawn in. */
    public function settlementsRecordTheirCurrency(): bool
    {
        return $this->settlements->recordTheirCurrency();
    }

    /** `column` when products carry a country of their own, `default_storefront` while they do not. */
    public function productsCountry(): string
    {
        return $this->countryColumnExists() ? 'column' : 'default_storefront';
    }

    /**
     * The orders created from `$from` up to, not including, `$before`, by market and status. A null market is the orders that have none.
     *
     * @param list<string>|null $statuses only these statuses, or every status
     *
     * @return list<array{market: ?string, status: string, orders: int, latest_at: ?string, stuck: int}> `stuck` is 0 here: see backlog()
     */
    public function orders(CarbonInterface $from, CarbonInterface $before, ?array $statuses = null): array
    {
        return $this->groupedOrders(from: $from, before: $before, statuses: $statuses, olderThan: null);
    }

    /**
     * ONE market's orders of the window, newest first, one page at a time (L-13): the order's own id, its status, the moment it was placed and how
     * many lines it has.
     *
     * **It is the counts' own list.** The market comes from `ordersWithTheirMarket()`, which is the subquery the counted figures are grouped from, so
     * a market's list and its count can never disagree about which orders are in it. An order of no single market is in no market's list, exactly as
     * it is in no market's count, and is never shown as Saudi.
     *
     * **No customer is in it.** Not a name, an address, a telephone number or an e-mail -- the same rule the export follows, and for the same reason:
     * who the order was for is not what an operator watching a market needs, and the owner has not yet decided what personal data a list of this API
     * may show (L-13, open decision). A line count answers "how big was it" without one.
     *
     * One page is one statement over the bounded range, and the page is read one row longer than it is answered so that `has_more` costs no count of
     * the whole table.
     *
     * @return array{orders: list<array{id: int, status: string, placed_at: ?string, lines: int}>, has_more: bool}
     */
    public function ordersOfMarket(string $market, CarbonInterface $from, CarbonInterface $before, int $offset, int $limit): array
    {
        $rows = DB::query()
            ->fromSub($this->ordersWithTheirMarket(from: $from, before: $before, statuses: null), 'attributed')
            ->where('attributed.market', $market)
            ->orderByDesc('attributed.created_at')
            ->orderByDesc('attributed.order_id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return [
            'orders' => $rows->take($limit)->map(static fn (object $row): array => [
                'id' => (int) $row->order_id,
                'status' => (string) $row->status,
                'placed_at' => $row->created_at === null ? null : PlatformAdminPresenter::timestamp(moment: Carbon::parse(time: $row->created_at)),
                'lines' => (int) $row->lines_count,
            ])->values()->all(),
            'has_more' => $rows->count() > $limit,
        ];
    }

    /**
     * The orders still waiting to be fulfilled, by market and status, created within the last days the API may read at all. A row also says how
     * many of its orders have waited longer than the age that makes an order stuck, so one read serves both the backlog and the alerts.
     *
     * @return list<array{market: ?string, status: string, orders: int, latest_at: ?string, stuck: int}>
     */
    public function backlog(): array
    {
        $range = PlatformWindow::lastDays(days: $this->settings->windowDaysMax());

        return $this->groupedOrders(
            from: $range->startsAt(),
            before: $range->endsBefore(),
            statuses: SlaService::AWAITING_SELLER_STATUSES,
            olderThan: now()->subHours($this->settings->pendingAgeHours()),
        );
    }

    /**
     * The refund requests made from `$from` up to, not including, `$before`, by the market of their product.
     *
     * @return list<array{market: ?string, total: int, pending: int}>
     */
    public function refundRequests(CarbonInterface $from, CarbonInterface $before): array
    {
        $country = $this->productCountry();

        $rows = DB::table('refund_requests')
            ->leftJoin('products', 'products.id', '=', 'refund_requests.product_id')
            ->where('refund_requests.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('refund_requests.created_at', '<', $before->format(self::DATE_FORMAT))
            ->selectRaw("{$country} AS country, COUNT(*) AS total, SUM(CASE WHEN refund_requests.status = 'pending' THEN 1 ELSE 0 END) AS pending")
            ->groupBy(DB::raw($country))
            ->get();

        return $this->inMarkets(rows: $rows, counts: ['total', 'pending']);
    }

    /**
     * What was sold from `$from` up to, not including, `$before`, grouped by the market of the ORDER, the company that sold it and the currency its
     * amounts are in. Nothing here adds two currencies together: each row is one currency, and an order whose currency is not recorded is counted
     * in a row of its own with no amount at all.
     *
     * The market is `orders.storefront_country`, the order's own record of what it was sold in, and NOT the country of its products the way the
     * order counts above read it. The two can differ -- a product may be moved to another market after it was sold -- and for money it is the
     * order's own record that is the fact. An order that carries no record has no market here and is never read as Saudi.
     *
     * An amount is counted only while the order's rate is exactly one, which is the one case where a stored amount IS an amount of the recorded
     * currency (OrderStorefrontSnapshot: "an amount of a storefront with its own currency is stored as it is, so the rate is one"). What any other
     * rate means for a stored amount is an open decision, so such an order is counted as unpriced rather than scaled by a guess -- the same rule
     * StorefrontCurrency applies when it decides whether an order's own currency can be honoured.
     *
     * @return list<array{market: ?string, entity_id: ?int, currency: ?string, orders: int, gross: ?string}>
     */
    public function sales(CarbonInterface $from, CarbonInterface $before): array
    {
        if (!$this->orderCurrencyRecorded()) {
            return [];
        }

        $market = $this->orderMarket();
        $entity = $this->orderEntityRecorded() ? 'orders.' . self::ENTITY_COLUMN : 'NULL';
        $priced = $this->pricedOrder();
        $currency = "CASE WHEN {$priced} = 1 THEN UPPER(TRIM(orders.currency_code)) END";

        // The engine groups and adds up: every amount of one market, company and currency is summed in SQL, so no two amounts are ever added in PHP,
        // where a decimal of twenty places would become a float first.
        $rows = DB::table('orders')
            ->where('orders.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $before->format(self::DATE_FORMAT))
            ->selectRaw(
                "{$market} AS market, {$entity} AS entity_id, {$currency} AS currency,
                COUNT(*) AS orders, SUM(CASE WHEN {$priced} = 1 THEN orders.order_amount ELSE 0 END) AS gross",
            )
            ->groupBy(DB::raw($market), DB::raw($entity), DB::raw($currency))
            ->get();

        return $this->salesRows(rows: $rows);
    }

    /** Whether an order records the currency its amounts are in, so that a sale can be put under a currency at all. */
    public function orderCurrencyRecorded(): bool
    {
        return $this->orderColumn(column: self::COUNTRY_COLUMN) && $this->orderColumn(column: 'currency_code') && $this->orderColumn(column: 'currency_rate');
    }

    /** Whether an order records the company that sold it, so that a sale can be put under one. */
    public function orderEntityRecorded(): bool
    {
        return $this->orderColumn(column: 'legal_entity_id');
    }

    /** Whether an order records how it was paid, so that what a market took can be put under a method at all. */
    public function orderPaymentRecorded(): bool
    {
        return $this->orderCurrencyRecorded() && $this->orderColumn(column: 'payment_method') && $this->orderColumn(column: 'payment_status') && $this->orderColumn(column: 'paid_amount');
    }

    /** Whether an order records the tax charged on top of its price. */
    public function orderTaxRecorded(): bool
    {
        return $this->orderCurrencyRecorded() && $this->orderColumn(column: 'total_tax_amount');
    }

    /** Whether an order records the tax that was already INSIDE a tax-included price (D1-07). Off is the platform of today, and the column may not be there at all. */
    public function includedTaxRecorded(): bool
    {
        return $this->orderColumn(column: 'included_tax_amount');
    }

    /**
     * Whether an order records WHICH tax model it was sold under (`tax_model`: `include` or `exclude`).
     *
     * This is what tells a tax-included sale from an ordinary one, and without it the included tax cannot be reported at all -- not because the amount
     * is missing but because there is no way to say which orders were supposed to have one. An installation without the column is answered with nulls
     * rather than with zeros that would read as "no tax-included sale here".
     */
    public function taxModelRecorded(): bool
    {
        return $this->orderColumn(column: 'tax_model');
    }

    /**
     * What one market took, by the method it was paid with and the currency it was paid in (L-13).
     *
     * **A method is never guessed.** `orders.payment_method` is nullable and an order that records none is a row of its own with a null method, not
     * folded into the commonest one and not renamed "unknown": which methods a market actually takes money through is the question this figure is
     * read for, and a bucket invented for it would be the wrong answer rather than a missing one.
     *
     * **Two currencies are never added.** The rule is the sales figure's, down to the condition: an amount counts only while the order records a
     * currency and a rate of exactly 1, so an order stored in another currency at a converted rate is counted as unpriced rather than scaled by a
     * guess at what the rate meant. `paid` is therefore the money the market took in its OWN money and nothing else.
     *
     * **Paid is what was received, not what was billed.** `paid_amount` is summed only over orders whose `payment_status` is paid; an order
     * part-paid or awaiting a gateway is counted in `unpaid_orders` and contributes no amount, because a figure that mixed money received with
     * money expected would be neither.
     *
     * One grouped statement over a date-bounded range, whatever the amount of data. Nothing of the customer is read or returned.
     *
     * @return list<array{method: ?string, currency: ?string, orders: int, paid_orders: int, unpaid_orders: int, paid: ?string}>
     */
    public function paymentsOfMarket(string $market, CarbonInterface $from, CarbonInterface $before): array
    {
        if (!$this->orderPaymentRecorded()) {
            return [];
        }

        $priced = $this->pricedOrder();
        $currency = "CASE WHEN {$priced} = 1 THEN UPPER(TRIM(orders.currency_code)) END";
        $method = "CASE WHEN orders.payment_method IS NOT NULL AND TRIM(orders.payment_method) <> '' THEN LOWER(TRIM(orders.payment_method)) END";
        $received = "CASE WHEN LOWER(TRIM(orders.payment_status)) = '" . self::PAID . "' THEN 1 ELSE 0 END";

        $rows = DB::table('orders')
            ->where('orders.' . self::COUNTRY_COLUMN, $market)
            ->where('orders.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $before->format(self::DATE_FORMAT))
            ->selectRaw(
                "{$method} AS method, {$currency} AS currency, COUNT(*) AS orders,
                SUM({$received}) AS paid_orders,
                SUM(CASE WHEN {$priced} = 1 AND {$received} = 1 THEN orders.paid_amount ELSE 0 END) AS paid",
            )
            ->groupBy(DB::raw($method), DB::raw($currency))
            ->get();

        return $this->paymentRows(rows: $rows);
    }

    /**
     * The tax one market recorded, by the company that sold it and the currency, in the two shapes tax is recorded in (L-13).
     *
     * **`charged` and `included` are not the same money and are never added.** `total_tax_amount` is tax put ON TOP of the price and is part of what
     * the customer was billed; `included_tax_amount` is the tax that was already INSIDE a tax-included price, written in a column of its own exactly
     * so that no reader adds it to the order total (see `App\Services\Tax\IncludedTax`). A summary that summed them would double the tax of a store
     * that runs both models.
     *
     * **A tax-included order whose included amount is not recorded says so.** While `gcc.tax.record_included_amount` is off nothing writes that
     * column, so a tax-included sale holds NULL there -- and NULL is reported as `included_not_recorded`, never as a zero. Zero is the one claim that
     * cannot be true of a taxed sale, and it is the claim the column exists to stop being made. `included_not_recorded` counts the orders `tax_model`
     * says were sold tax-INCLUDED and nothing else: an ordinary tax-on-top sale has no included tax to record and is not a gap, which is why the count
     * is taken from the model and not from the amount being absent. Without `tax_model` there is no way to tell the two apart, so both counts are null.
     *
     * **`tax_included_orders` is the denominator of that.** How many of the window's orders were sold tax-included at all, so that "4 of 200 recorded
     * nothing" and "4 of 4 recorded nothing" are different readings of the same four.
     *
     * This is a SUMMARY and not a compliant tax return: it is what a market collected, grouped, and the configuration behind it is deliberately not
     * here (docs/gcc/GCC-20-MOBILE-OR-DESKTOP.md classifies the return and the configuration as desktop work).
     *
     * One grouped statement over a date-bounded range, whatever the amount of data.
     *
     * @return list<array{legal_entity_id: ?int, currency: ?string, orders: int, charged: ?string, included: ?string, tax_included_orders: ?int, included_not_recorded: ?int}>
     */
    public function taxOfMarket(string $market, CarbonInterface $from, CarbonInterface $before): array
    {
        if (!$this->orderTaxRecorded()) {
            return [];
        }

        $priced = $this->pricedOrder();
        $currency = "CASE WHEN {$priced} = 1 THEN UPPER(TRIM(orders.currency_code)) END";
        $entity = $this->orderEntityRecorded() ? 'orders.' . self::ENTITY_COLUMN : 'NULL';
        $included = $this->includedTaxRecorded() ? 'orders.included_tax_amount' : 'NULL';
        $taxIncluded = $this->taxModelRecorded() ? "CASE WHEN LOWER(TRIM(orders.tax_model)) = '" . self::TAX_INCLUDED . "' THEN 1 ELSE 0 END" : 'NULL';
        $unrecorded = $this->taxModelRecorded()
            ? "CASE WHEN {$taxIncluded} = 1 AND {$included} IS NULL THEN 1 ELSE 0 END"
            : 'NULL';

        $rows = DB::table('orders')
            ->where('orders.' . self::COUNTRY_COLUMN, $market)
            ->where('orders.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $before->format(self::DATE_FORMAT))
            ->selectRaw(
                "{$entity} AS entity_id, {$currency} AS currency, COUNT(*) AS orders,
                SUM(CASE WHEN {$priced} = 1 THEN orders.total_tax_amount ELSE 0 END) AS charged,
                SUM(CASE WHEN {$priced} = 1 THEN COALESCE({$included}, 0) ELSE 0 END) AS included,
                SUM({$taxIncluded}) AS tax_included_orders,
                SUM({$unrecorded}) AS included_not_recorded",
            )
            ->groupBy(DB::raw($entity), DB::raw($currency))
            ->get();

        return $this->taxRows(rows: $rows);
    }

    /**
     * The SQL condition under which an order's stored amounts may be read as money of its own currency: a currency recorded, and a rate of exactly 1.
     *
     * One place rather than three. `sales()` wrote this condition first and `paymentsOfMarket()` and `taxOfMarket()` must answer under exactly the same
     * rule, or a market's sales and the money it took would disagree about which orders were priced at all.
     */
    private function pricedOrder(): string
    {
        return "CASE WHEN orders.currency_code IS NOT NULL AND TRIM(orders.currency_code) <> '' AND orders.currency_rate = 1 THEN 1 ELSE 0 END";
    }

    /**
     * @param iterable<object> $rows
     *
     * @return list<array{method: ?string, currency: ?string, orders: int, paid_orders: int, unpaid_orders: int, paid: ?string}>
     */
    private function paymentRows(iterable $rows): array
    {
        $reported = [];

        foreach ($rows as $row) {
            $currency = $row->currency === null ? null : (string) $row->currency;
            $orders = (int) $row->orders;
            $paidOrders = (int) $row->paid_orders;

            $reported[] = [
                'method' => $row->method === null ? null : (string) $row->method,
                'currency' => $currency,
                'orders' => $orders,
                'paid_orders' => $paidOrders,
                'unpaid_orders' => $orders - $paidOrders,
                'paid' => $currency === null ? null : PlatformAdminPresenter::amount(sum: $row->paid),
            ];
        }

        usort($reported, static fn (array $one, array $other): int => [$one['currency'], $one['method']] <=> [$other['currency'], $other['method']]);

        return $reported;
    }

    /**
     * @param iterable<object> $rows
     *
     * @return list<array{legal_entity_id: ?int, currency: ?string, orders: int, charged: ?string, included: ?string, tax_included_orders: ?int, included_not_recorded: ?int}>
     */
    private function taxRows(iterable $rows): array
    {
        $reported = [];

        foreach ($rows as $row) {
            $currency = $row->currency === null ? null : (string) $row->currency;

            $reported[] = [
                'legal_entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
                'currency' => $currency,
                'orders' => (int) $row->orders,
                'charged' => $currency === null ? null : PlatformAdminPresenter::amount(sum: $row->charged),
                'included' => $currency === null ? null : PlatformAdminPresenter::amount(sum: $row->included),
                'tax_included_orders' => $row->tax_included_orders === null ? null : (int) $row->tax_included_orders,
                'included_not_recorded' => $row->included_not_recorded === null ? null : (int) $row->included_not_recorded,
            ];
        }

        usort($reported, static fn (array $one, array $other): int => [$one['currency'], $one['legal_entity_id']] <=> [$other['currency'], $other['legal_entity_id']]);

        return $reported;
    }

    /**
     * The live physical products at no stock, at low stock and BELOW zero, by market. How low is low is what the inventory screens use: the stock
     * limit of the seller who owns the product when it is one whole unit or more, and otherwise the platform's, and never less than one.
     *
     * `oversold` is a product whose stock has gone past zero: units were sold that were not there. `products.current_stock` is a signed integer, so
     * this is a real state of the data and not a shape the schema forbids -- the monitoring page counts it as a finding of its own. It is a SUBSET of
     * `zero`, which has always counted `current_stock <= 0`, and the two are deliberately not made to exclude one another: the oversell is the
     * sharper reading of the same rows, and narrowing `zero` would move a figure the platform already reports.
     *
     * It costs no statement of its own: one more pair of conditional sums over exactly the population the zero and low figures already read.
     *
     * @return list<array{market: ?string, zero: int, low: int, oversold: int, oversold_units: int}>
     */
    public function stock(): array
    {
        $country = $this->productCountry();
        $limit = $this->stockLimitSql();

        $rows = $this->liveProducts()
            ->selectRaw(
                "{$country} AS country,
                SUM(CASE WHEN products.current_stock <= 0 THEN 1 ELSE 0 END) AS zero,
                SUM(CASE WHEN products.current_stock > 0 AND products.current_stock <= {$limit} THEN 1 ELSE 0 END) AS low,
                SUM(CASE WHEN products.current_stock < 0 THEN 1 ELSE 0 END) AS oversold,
                SUM(CASE WHEN products.current_stock < 0 THEN -products.current_stock ELSE 0 END) AS oversold_units",
            )
            ->groupBy(DB::raw($country))
            ->get();

        return $this->inMarkets(rows: $rows, counts: ['zero', 'low', 'oversold', 'oversold_units']);
    }

    /**
     * The stock promised to orders and not yet shipped, by the market of the product it is held for: how many holds there are and how many units
     * they hold. Only a hold in state `reserved` holds anything -- `committed` and `released` are the two ends of a hold's life
     * (InventoryReservation, StockHoldReconciler: "Committed and released holds are the two correct ends") -- so a shop that fulfils its orders
     * reports few of these, and a figure that keeps climbing is the finding.
     *
     * A hold whose product has been deleted, or whose product is of a country nobody configured, has no market and is counted apart, never as
     * Saudi, exactly as a refund request of a deleted product is.
     *
     * @return list<array{market: ?string, holds: int, units: int}>
     */
    public function reservations(): array
    {
        if (!$this->inventoryTable(table: 'inventory_reservations')) {
            return [];
        }

        $country = $this->productCountry();

        $rows = DB::table('inventory_reservations')
            ->leftJoin('products', 'products.id', '=', 'inventory_reservations.product_id')
            ->where('inventory_reservations.state', InventoryReservation::RESERVED)
            ->selectRaw("{$country} AS country, COUNT(*) AS holds, COALESCE(SUM(inventory_reservations.quantity), 0) AS units")
            ->groupBy(DB::raw($country))
            ->get();

        return $this->inMarkets(rows: $rows, counts: ['holds', 'units']);
    }

    /**
     * How the stock of a market is divided between the channels that offer it, by the market of the pool's product: how many pools there are, how
     * many of them could not give every channel its share, and how many units short those are.
     *
     * `inventory_pools.deficit` is the division's own record of what it could not satisfy, written by the allocator rather than derived here, so
     * this figure is the allocator's verdict and not a second opinion about it. A pool with no deficit is a market whose channels are all served.
     *
     * @return list<array{market: ?string, pools: int, short: int, short_units: int}>
     */
    public function allocations(): array
    {
        if (!$this->inventoryTable(table: 'inventory_pools')) {
            return [];
        }

        $country = $this->productCountry();

        $rows = DB::table('inventory_pools')
            ->leftJoin('products', 'products.id', '=', 'inventory_pools.product_id')
            ->selectRaw(
                "{$country} AS country, COUNT(*) AS pools,
                SUM(CASE WHEN inventory_pools.deficit > 0 THEN 1 ELSE 0 END) AS short,
                COALESCE(SUM(inventory_pools.deficit), 0) AS short_units",
            )
            ->groupBy(DB::raw($country))
            ->get();

        return $this->inMarkets(rows: $rows, counts: ['pools', 'short', 'short_units']);
    }

    /** Whether this installation keeps the stock promised to orders, so that a reservation figure can be reported at all. */
    public function reservationsRecorded(): bool
    {
        return $this->inventoryTable(table: 'inventory_reservations');
    }

    /** Whether this installation divides a stock between channels, so that an allocation figure can be reported at all. */
    public function allocationsRecorded(): bool
    {
        return $this->inventoryTable(table: 'inventory_pools');
    }

    /**
     * ONE market's live products, newest shortage first, one page at a time (L-13): the drill-down under the stock figures and the stock alerts.
     *
     * **It is the counts' own list.** The population is `liveProducts()`, which is the very query `stock()` groups its zero, low, oversold and
     * oversold-units figures from, so a market's list and its counts can never disagree about which products are in it. A product that is not live --
     * unpublished, not approved, digital, or of a shop that is pending or deleted -- is in neither, however far below zero its stock stands.
     *
     * **The product is named.** Unlike the orders list, which gives no name at all, a row carries `products.name`: a product name is published to every
     * shopper on the storefront, so it is not confidential, and a shortage list without it forces an operator to the desktop panel for every row, which is
     * the whole thing the drill-down exists to save. It is the product's own recorded name and not a translation, because this API localises nothing.
     *
     * **No shop, and no money.** Not the shop's name or id, and not a price: who sells a product and what it costs are a different question from whether
     * the market has run out of it, and the capability that opens this is meant to be safe to give an operator trusted with neither.
     *
     * `stock_limit` is the limit that applies to THIS product -- the shop's own when it has set one, the platform's otherwise -- so `low` can be read
     * rather than recomputed by the app against a limit it would have to guess.
     *
     * Ordered by stock ascending then by id, so the market's worst row is first and a page cannot drop or repeat one. The page is read one row longer
     * than it is answered, so `has_more` costs no count of the table.
     *
     * @return array{products: list<array{id: int, name: string, stock: int, stock_limit: int, low: bool, oversold: bool}>, has_more: bool}
     */
    public function productsOfMarket(string $market, int $offset, int $limit): array
    {
        $rows = $this->liveProducts()
            ->whereRaw($this->productCountry() . ' = ?', [$market])
            ->selectRaw("products.id AS id, products.name AS name, products.current_stock AS stock, {$this->stockLimitSql()} AS stock_limit")
            ->orderBy('products.current_stock')
            ->orderBy('products.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return [
            'products' => $rows->take($limit)->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'stock' => (int) $row->stock,
                'stock_limit' => (int) $row->stock_limit,
                'low' => (int) $row->stock > 0 && (int) $row->stock <= (int) $row->stock_limit,
                'oversold' => (int) $row->stock < 0,
            ])->values()->all(),
            'has_more' => $rows->count() > $limit,
        ];
    }

    /**
     * ONE market's refund requests of the window as a LIST, newest first, one page at a time (L-13): the drill-down under the returns figures.
     *
     * **It is the counts' own list.** The market is the market of the product the request was made for, which is exactly what `refundRequests()` groups
     * its totals by, so a market's list is as long as its count for the same window. A request for a product that has been deleted, or for one of a
     * country nobody configured, is in no market's list and is never shown as Saudi.
     *
     * **No customer, and nothing the customer wrote.** Not `customer_id`, not the reason they gave, not the images they attached, not the payment
     * details, not the notes either side added -- and no such column is read. What personal data a list of this API may show is an open owner decision
     * (L-13), so until it is answered none is shown. `order_id` and `product_id` are what an operator follows a return back through.
     *
     * **No amount.** The money of a return is in the report's settlement figures and in the export, each behind a capability of its own. A returns
     * list answers "which returns are waiting", and putting money into a read that an operator not trusted with money may hold would be a decision
     * rather than a drill-down.
     *
     * **An id of nothing is answered as nothing.** `refund_requests.order_id` and `product_id` are `unsignedBigInteger` columns that DEFAULT to zero, so a
     * request that names no order carries 0 and not NULL -- and 0 points at no order. Answering it would send an app looking for order zero, which is the
     * same class of mistake as reporting a market nobody recorded as the default one. Both are reported as null, which is what they mean.
     *
     * @return array{returns: list<array{id: int, status: string, requested_at: ?string, order_id: ?int, product_id: ?int}>, has_more: bool}
     */
    public function returnsOfMarket(string $market, CarbonInterface $from, CarbonInterface $before, int $offset, int $limit): array
    {
        $rows = DB::table('refund_requests')
            ->leftJoin('products', 'products.id', '=', 'refund_requests.product_id')
            ->where('refund_requests.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('refund_requests.created_at', '<', $before->format(self::DATE_FORMAT))
            ->whereRaw($this->productCountry() . ' = ?', [$market])
            ->select(['refund_requests.id', 'refund_requests.status', 'refund_requests.created_at', 'refund_requests.order_id', 'refund_requests.product_id'])
            ->orderByDesc('refund_requests.created_at')
            ->orderByDesc('refund_requests.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return [
            'returns' => $rows->take($limit)->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'requested_at' => $row->created_at === null ? null : PlatformAdminPresenter::timestamp(moment: Carbon::parse(time: $row->created_at)),
                'order_id' => self::anIdOrNone(given: $row->order_id),
                'product_id' => self::anIdOrNone(given: $row->product_id),
            ])->values()->all(),
            'has_more' => $rows->count() > $limit,
        ];
    }

    /**
     * ONE market's stock that is promised or divided, product by product (L-13): the drill-down under the reservation and allocation figures.
     *
     * **It is the counts' own list, on both halves.** The holds counted are the `reserved` ones only -- `committed` and `released` are the two correct
     * ends of a hold's life -- which is what `reservations()` counts, and the shortfall is `inventory_pools.deficit`, the allocator's own record, which
     * is what `allocations()` counts. One row per product, because that is the key both figures are already per.
     *
     * **A row is here only because it holds or divides something.** A product with no reserved hold and no pool is not in the list: an operator reading
     * "what is promised in Kuwait" wants the promises, not the catalogue, and the shortage list is `productsOfMarket()`.
     *
     * **An absent table is said, never shown as a zero.** The two inventory tables are the dormant half of the inventory stack, and "no pool is short"
     * and "nothing divides a stock here" are different facts about a market. Each half is reported as recorded or not, and when NEITHER is recorded the
     * list is empty with both said so -- so a Saudi installation whose migrations have not run cannot read as a market that promises nothing.
     *
     * One page is one statement whichever halves exist. Ordered by product id: the figure worth sorting by differs by which half is recorded, and a list
     * an operator pages through has to be stable before it is clever -- every row carries its own figures, so a page can be arranged by the app.
     *
     * @return array{products: list<array{product_id: int, stock: int, holds: ?int, reserved_units: ?int, pools: ?int, short_units: ?int}>, has_more: bool, recorded: array{holds: bool, pools: bool}}
     */
    public function stockHoldsOfMarket(string $market, int $offset, int $limit): array
    {
        $holdsRecorded = $this->inventoryTable(table: 'inventory_reservations');
        $poolsRecorded = $this->inventoryTable(table: 'inventory_pools');
        $recorded = ['holds' => $holdsRecorded, 'pools' => $poolsRecorded];

        if (!$holdsRecorded && !$poolsRecorded) {
            return ['products' => [], 'has_more' => false, 'recorded' => $recorded];
        }

        $query = DB::table('products')
            ->whereRaw($this->productCountry() . ' = ?', [$market])
            ->select(['products.id', 'products.current_stock']);

        if ($holdsRecorded) {
            $query->leftJoinSub(
                DB::table('inventory_reservations')
                    ->where('state', InventoryReservation::RESERVED)
                    ->groupBy('product_id')
                    ->selectRaw('product_id, COUNT(*) AS holds, COALESCE(SUM(quantity), 0) AS units'),
                'held',
                'held.product_id',
                '=',
                'products.id',
            )->addSelect(['held.holds', 'held.units']);
        }

        if ($poolsRecorded) {
            $query->leftJoinSub(
                DB::table('inventory_pools')
                    ->groupBy('product_id')
                    ->selectRaw('product_id, COUNT(*) AS pools, COALESCE(SUM(deficit), 0) AS short_units'),
                'pooled',
                'pooled.product_id',
                '=',
                'products.id',
            )->addSelect(['pooled.pools', 'pooled.short_units']);
        }

        $rows = $query
            ->where(static function (Builder $any) use ($holdsRecorded, $poolsRecorded): void {
                if ($holdsRecorded) {
                    $any->orWhereNotNull('held.product_id');
                }
                if ($poolsRecorded) {
                    $any->orWhereNotNull('pooled.product_id');
                }
            })
            ->orderBy('products.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        return [
            'products' => $rows->take($limit)->map(static fn (object $row): array => [
                'product_id' => (int) $row->id,
                'stock' => (int) $row->current_stock,
                'holds' => $holdsRecorded ? (int) ($row->holds ?? 0) : null,
                'reserved_units' => $holdsRecorded ? (int) ($row->units ?? 0) : null,
                'pools' => $poolsRecorded ? (int) ($row->pools ?? 0) : null,
                'short_units' => $poolsRecorded ? (int) ($row->short_units ?? 0) : null,
            ])->values()->all(),
            'has_more' => $rows->count() > $limit,
            'recorded' => $recorded,
        ];
    }

    /**
     * The population every stock figure of this API reads, as a query: a physical product that is published and approved, and either the platform's own
     * or one of an approved shop that has not been deleted.
     *
     * It is a method rather than two copies because the counts and the LIST must read the same rows. That is the discipline the orders list established
     * (`ordersWithTheirMarket()` serves the count and the list alike): if the two ever diverge, a market's list and its own figures disagree about which
     * products are in it and nothing says which is right.
     */
    private function liveProducts(): Builder
    {
        return DB::table('products')
            ->leftJoin('sellers', static function (JoinClause $join): void {
                $join->on('sellers.id', '=', 'products.user_id')->where('products.added_by', '=', 'seller')->whereNull('sellers.deleted_at');
            })
            ->where('products.product_type', 'physical')
            ->where('products.status', 1)
            ->where('products.request_status', 1)
            ->where(static fn (Builder $query) => $query->where('products.added_by', 'admin')->orWhere('sellers.status', 'approved'));
    }

    /**
     * An id as the API reports it: null when the column holds nothing, which on these tables includes the ZERO that an `unsignedBigInteger` column with a
     * default puts there when nothing was written. There is no row with id 0, so 0 is "none" written in a column that cannot say so.
     */
    private static function anIdOrNone(mixed $given): ?int
    {
        return $given === null || (int) $given === 0 ? null : (int) $given;
    }

    /** The stock limit that applies to a product, as SQL: the shop's own when it has set one, the platform's setting otherwise. */
    private function stockLimitSql(): string
    {
        $platformLimit = max(1, (int) getWebConfig(name: 'stock_limit'));

        return "CASE WHEN sellers.stock_limit >= 1 THEN sellers.stock_limit ELSE {$platformLimit} END";
    }

    /**
     * @param list<string>|null $statuses
     *
     * @return list<array{market: ?string, status: string, orders: int, latest_at: ?string, stuck: int}>
     */
    private function groupedOrders(CarbonInterface $from, CarbonInterface $before, ?array $statuses, ?CarbonInterface $olderThan): array
    {
        $stuck = $olderThan === null ? '0' : 'SUM(CASE WHEN attributed.created_at < ? THEN 1 ELSE 0 END)';

        return DB::query()
            ->fromSub($this->ordersWithTheirMarket(from: $from, before: $before, statuses: $statuses), 'attributed')
            ->selectRaw(
                "attributed.market AS market, attributed.status AS status, COUNT(*) AS orders, MAX(attributed.created_at) AS latest_at, {$stuck} AS stuck",
                $olderThan === null ? [] : [$olderThan->format(self::DATE_FORMAT)],
            )
            ->groupBy('attributed.market', 'attributed.status')
            ->get()
            ->map(static fn (object $row): array => ['market' => $row->market, 'status' => (string) $row->status, 'orders' => (int) $row->orders, 'latest_at' => $row->latest_at, 'stuck' => (int) $row->stuck])
            ->all();
    }

    /** One row per order of the range, with the market the order belongs to or null. An order without a line has no country to compare, so it has no market. */
    private function ordersWithTheirMarket(CarbonInterface $from, CarbonInterface $before, ?array $statuses): Builder
    {
        $country = $this->productCountry();
        $codes = $this->markets->codes();
        $configured = $codes === [] ? 'NULL' : implode(', ', array_fill(0, count($codes), '?'));

        $orders = DB::table('orders')
            ->leftJoin('order_details', 'order_details.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_details.product_id')
            ->where('orders.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $before->format(self::DATE_FORMAT))
            ->groupBy('orders.id', 'orders.order_status', 'orders.created_at')
            ->selectRaw(
                "orders.id AS order_id, orders.order_status AS status, orders.created_at AS created_at, COUNT(order_details.id) AS lines_count,
                CASE WHEN COUNT(products.id) = COUNT(order_details.id) AND MIN({$country}) = MAX({$country}) AND MIN({$country}) IN ({$configured})
                    THEN MIN({$country}) END AS market",
                $codes,
            );

        return $statuses === null ? $orders : $orders->whereIn('orders.order_status', $statuses);
    }

    /**
     * The SQL for the country of the product a row points at, null when there is none. While products carry no country it is the default
     * storefront's for every product that exists.
     */
    private function productCountry(): string
    {
        if ($this->countryColumnExists()) {
            return 'products.' . self::COUNTRY_COLUMN;
        }

        return 'CASE WHEN products.id IS NULL THEN NULL ELSE ' . DB::connection()->getPdo()->quote($this->markets->defaultCountry()) . ' END';
    }

    private function countryColumnExists(): bool
    {
        return $this->countryColumn ??= Schema::hasColumn('products', self::COUNTRY_COLUMN);
    }

    private function orderColumn(string $column): bool
    {
        return $this->orderColumns[$column] ??= Schema::hasColumn('orders', $column);
    }

    /**
     * Whether one of the inventory tables exists here, asked once per table and per request. They are the dormant half of the inventory stack: an
     * installation whose migration has not run has no such table, and the answer reports that rather than a zero, because "no pool is short" and
     * "nothing divides a stock here" are different facts about a market.
     */
    private function inventoryTable(string $table): bool
    {
        return $this->inventoryTables[$table] ??= Schema::hasTable($table);
    }

    /**
     * The SQL for the market an ORDER was sold in: its own recorded country while that is a configured market, and null otherwise -- an order of a
     * country nobody configured, and one that recorded none, belong to no market and are never read as the default one. The codes are written into
     * the statement rather than bound, as the product country above is, and they can only be two capital letters (PlatformMarkets).
     */
    private function orderMarket(): string
    {
        $codes = $this->markets->codes();
        $column = 'orders.' . self::COUNTRY_COLUMN;

        if ($codes === []) {
            return 'NULL';
        }

        return "CASE WHEN {$column} IN ('" . implode("', '", $codes) . "') THEN {$column} END";
    }

    /**
     * The rows the engine grouped, as the API reports them. A currency is kept only on a priced row, and an unpriced row carries no amount at all,
     * so an order whose currency is not recorded can never be added to one whose currency is.
     *
     * @param iterable<object> $rows
     *
     * @return list<array{market: ?string, entity_id: ?int, currency: ?string, orders: int, gross: ?string}>
     */
    private function salesRows(iterable $rows): array
    {
        $reported = [];

        foreach ($rows as $row) {
            $currency = $row->currency === null ? null : (string) $row->currency;

            $reported[] = [
                'market' => $row->market === null ? null : (string) $row->market,
                'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
                'currency' => $currency,
                'orders' => (int) $row->orders,
                'gross' => $currency === null ? null : PlatformAdminPresenter::amount(sum: $row->gross),
            ];
        }

        return $reported;
    }

    /**
     * Rows grouped by country, turned into rows grouped by market. Every country that is not a configured market, and a missing one, is the
     * one row with a null market.
     *
     * @param iterable<object> $rows
     * @param list<string> $counts the columns to add up
     *
     * @return list<array<string, mixed>>
     */
    private function inMarkets(iterable $rows, array $counts): array
    {
        $configured = $this->markets->codes();
        $byMarket = [];

        foreach ($rows as $row) {
            $market = in_array($row->country, $configured, true) ? $row->country : null;
            $key = $market ?? '';
            $byMarket[$key] ??= ['market' => $market] + array_fill_keys($counts, 0);

            foreach ($counts as $column) {
                $byMarket[$key][$column] += (int) $row->{$column};
            }
        }

        return array_values($byMarket);
    }
}
