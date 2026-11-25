<?php

namespace App\Services\PlatformAdmin;

use App\Services\Marketplace\SettlementTotals;
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

    /** What an order records of the money it was sold in and the company that sold it (GCC-05, GCC-13); nullable and empty on every order today. */
    private const ORDER_CURRENCY_COLUMN = 'currency_code';

    private const ORDER_ENTITY_COLUMN = 'legal_entity_id';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private ?bool $countryColumn = null;

    /** @var array<string, bool> */
    private array $orderColumns = [];

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformAdminSettings $settings,
        private readonly SettlementTotals $settlements,
    ) {
    }

    /**
     * What the platform settled in the window, by the company that owes it and the currency it is drawn in (E-17).
     *
     * Read through `App\Services\Marketplace\SettlementTotals` and not here, because the settlement table names the shop it pays in a column
     * this area of the application may not mention at all -- the same indirection, and for the same reason, as the shop issue counts.
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
     * What the orders of the range came to, by market, by the COMPANY that sold them and by the CURRENCY they were sold in (E-17).
     *
     * **Two currencies are never added, and that is the whole shape of this figure.** A platform of six markets prices in six currencies whose
     * amounts are not interchangeable, so there is no such thing as "sales" without a currency beside it: every row here names one, and an
     * order that records none is NOT in any row. It is counted apart, with the reason, so a reader is told what is missing instead of being
     * handed a total that quietly leaves it out -- and on today's platform, where no order records a currency, that is EVERY order, which is
     * why this answers exactly what the overview answered before: no figure, and the reason why.
     *
     * The amount is summed in the database and read back as text, never as a float: `orders.order_amount` is a wide decimal and turning each
     * one into a float to add them is how a financial total comes to differ from the rows it was made of.
     *
     * The company is the one the order FROZE, not one re-derived now: a company edited, renamed or deleted since the sale does not move what
     * the sale was. An order recording none is reported under a null company rather than under the platform's, because "we do not know" and
     * "the platform's own" are different answers and only one of them is true of a legacy order.
     *
     * @return list<array{market: ?string, entity: ?int, currency: ?string, orders: int, amount: ?string}>
     */
    public function sales(CarbonInterface $from, CarbonInterface $before): array
    {
        $also = ['orders.order_amount'];

        foreach ([self::ORDER_CURRENCY_COLUMN, self::ORDER_ENTITY_COLUMN] as $column) {
            if ($this->orderRecords(column: $column)) {
                $also[] = 'orders.' . $column;
            }
        }

        $currency = $this->orderColumn(column: self::ORDER_CURRENCY_COLUMN);
        $entity = $this->orderColumn(column: self::ORDER_ENTITY_COLUMN);

        return DB::query()
            ->fromSub($this->ordersWithTheirMarket(from: $from, before: $before, statuses: null, also: $also), 'attributed')
            ->selectRaw(
                "attributed.market AS market, {$entity} AS entity, {$currency} AS currency,
                COUNT(*) AS orders, SUM(attributed.order_amount) AS amount",
            )
            ->groupBy(DB::raw("attributed.market, {$entity}, {$currency}"))
            ->get()
            ->map(static fn (object $row): array => [
                'market' => $row->market,
                'entity' => $row->entity === null ? null : (int) $row->entity,
                'currency' => ($row->currency ?? '') === '' ? null : (string) $row->currency,
                'orders' => (int) $row->orders,
                'amount' => $row->amount === null ? null : (string) $row->amount,
            ])
            ->all();
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

    /**
     * One row per order of the range, with the market the order belongs to or null. An order without a line has no country to compare, so it has
     * no market.
     *
     * `$also` names further columns of `orders` a caller needs on each row. They are SELECTED and GROUPED BY, both: grouping by `orders.id`
     * already determines them, but MySQL 8 runs with ONLY_FULL_GROUP_BY and refuses a selected column it was not told is determined. A caller
     * that passes nothing gets the query that was here before, statement for statement.
     *
     * @param list<string>|null $statuses
     * @param list<string> $also
     */
    private function ordersWithTheirMarket(CarbonInterface $from, CarbonInterface $before, ?array $statuses, array $also = []): Builder
    {
        $country = $this->productCountry();
        $codes = $this->markets->codes();
        $configured = $codes === [] ? 'NULL' : implode(', ', array_fill(0, count($codes), '?'));
        $extra = $also === [] ? '' : ', ' . implode(', ', $also);

        $orders = DB::table('orders')
            ->leftJoin('order_details', 'order_details.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_details.product_id')
            ->where('orders.created_at', '>=', $from->format(self::DATE_FORMAT))
            ->where('orders.created_at', '<', $before->format(self::DATE_FORMAT))
            ->groupBy('orders.id', 'orders.order_status', 'orders.created_at', ...$also)
            ->selectRaw(
                "orders.id AS order_id, orders.order_status AS status, orders.created_at AS created_at{$extra},
                CASE WHEN COUNT(products.id) = COUNT(order_details.id) AND MIN({$country}) = MAX({$country}) AND MIN({$country}) IN ({$configured})
                    THEN MIN({$country}) END AS market",
                $codes,
            );

        return $statuses === null ? $orders : $orders->whereIn('orders.order_status', $statuses);
    }

    /**
     * The SQL for one of the order's own snapshot columns, or the literal NULL while its migration has not run.
     *
     * NULL rather than a guess, and this is the one place it matters most: a market's own currency is what makes a sales figure mean anything,
     * and standing the platform's currency in for an order that records none would turn six markets' money into one total that is true of no
     * market. "Not recorded" is the honest answer and the overview prints it as a reason.
     */
    private function orderColumn(string $column): string
    {
        return $this->orderRecords(column: $column) ? 'attributed.' . $column : 'NULL';
    }

    private function orderRecords(string $column): bool
    {
        return $this->orderColumns[$column] ??= Schema::hasColumn('orders', $column);
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
