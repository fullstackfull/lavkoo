<?php

namespace App\Services\PlatformAdmin;

use App\Services\Marketplace\SlaService;
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
 *  - a product is in its own market, and counts as stock only while it is live: physical, active, approved, and its seller approved.
 *
 * While the column is missing (the products migration of the storefront has not run) every product belongs to the default storefront, which is
 * what the storefront code itself assumes, and the answer says so.
 *
 * One instance serves a whole request, so that the overview and the alerts ask the schema once.
 */
#[Scoped]
final class PlatformMarketFigures
{
    private const COUNTRY_COLUMN = 'storefront_country';

    private const ENTITY_COLUMN = 'legal_entity_id';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private ?bool $countryColumn = null;

    /** @var array<string, bool> whether the orders table carries a column, asked once per column and per request */
    private array $orderColumns = [];

    public function __construct(private readonly PlatformMarkets $markets, private readonly PlatformAdminSettings $settings)
    {
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
        $priced = "CASE WHEN orders.currency_code IS NOT NULL AND TRIM(orders.currency_code) <> '' AND orders.currency_rate = 1 THEN 1 ELSE 0 END";
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

    /**
     * The live physical products at no stock and at low stock, by market. How low is low is what the inventory screens use: the stock limit of the
     * seller who owns the product when it is one whole unit or more, and otherwise the platform's, and never less than one.
     *
     * @return list<array{market: ?string, zero: int, low: int}>
     */
    public function stock(): array
    {
        $country = $this->productCountry();
        $platformLimit = max(1, (int) getWebConfig(name: 'stock_limit'));
        $limit = "CASE WHEN sellers.stock_limit >= 1 THEN sellers.stock_limit ELSE {$platformLimit} END";

        $rows = DB::table('products')
            ->leftJoin('sellers', static function (JoinClause $join): void {
                $join->on('sellers.id', '=', 'products.user_id')->where('products.added_by', '=', 'seller')->whereNull('sellers.deleted_at');
            })
            ->where('products.product_type', 'physical')
            ->where('products.status', 1)
            ->where('products.request_status', 1)
            ->where(static fn (Builder $query) => $query->where('products.added_by', 'admin')->orWhere('sellers.status', 'approved'))
            ->selectRaw("{$country} AS country, SUM(CASE WHEN products.current_stock <= 0 THEN 1 ELSE 0 END) AS zero, SUM(CASE WHEN products.current_stock > 0 AND products.current_stock <= {$limit} THEN 1 ELSE 0 END) AS low")
            ->groupBy(DB::raw($country))
            ->get();

        return $this->inMarkets(rows: $rows, counts: ['zero', 'low']);
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
                "orders.id AS order_id, orders.order_status AS status, orders.created_at AS created_at,
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
