<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Support\Carbon;

/**
 * The overview of the markets an admin asked about: for each one, its orders, what is waiting to be fulfilled, its refund requests and its
 * stock, all counted in the database over a bounded range (see PlatformMarketFigures for what puts a figure in a market).
 *
 * Two ranges are used and each says which: the orders and the refund requests of the window the admin asked for, and the backlog, which is a
 * state and not a flow, read over the last stretch the API may read at all (the longest window), so that an order that has waited longer than
 * the window is still waiting. The backlog read also says how many of its orders are stuck, which is what the alerts need.
 *
 * Sales ARE reported now, in a shape that cannot add two currencies: a row per selling company and per currency, and every order that records no
 * currency counted APART with the reason. The amounts are summed in the database and carried as text, because turning a wide decimal into a float
 * to add it is how a financial total comes to differ from the rows it was made of. There is no total over the currencies and there will not be
 * one: six markets' money is not interchangeable, and a reader who wants one figure has to say which currency and at which rate, which is a
 * question with an owner and a date on it rather than an arithmetic convenience.
 *
 * **While no order in the window records a currency, the answer is what it always was, key for key** -- `by_currency` null with the reason, the
 * same `attribution.currency`, and no `sales` entry in the attribution at all. That is every order on the platform today, and it is why the tests
 * written before this change assert the same bytes after it.
 *
 * Settlements are reported too, in the same shape and for the same reason, but NOT inside a market: a settlement has no market, it has a company,
 * a currency and a period. It is one new key of the answer, and an installation that keeps no settlements -- `vendor_settlements` has no migration
 * in this repository -- says so rather than reporting zeros that look like a quiet month.
 */
final class PlatformOverviewService
{
    private const NO_MARKET_REASON = 'no_single_market';

    private const NO_CURRENCY_REASON = 'order_currency_not_recorded';

    private const NO_SETTLEMENTS_REASON = 'no_settlements_are_recorded_on_this_installation';

    private const SETTLEMENT_CURRENCY_REASON = 'settlement_currency_not_recorded';

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformAlertService $alerts,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(MarketSelection $selection, PlatformWindow $window): array
    {
        $figures = [
            'orders' => $this->figures->orders(from: $window->startsAt(), before: $window->endsBefore()),
            'sales' => $this->figures->sales(from: $window->startsAt(), before: $window->endsBefore()),
            'backlog' => $this->figures->backlog(),
            'refunds' => $this->figures->refundRequests(from: $window->startsAt(), before: $window->endsBefore()),
            'stock' => $this->figures->stock(),
        ];

        // Whether any order of this window says what it was sold in. Read from the figures rather than from the schema: a column that exists and
        // is empty on every order is a platform that records no currency, and answering "per order: true" for it would be a claim about data
        // that is not there.
        $currencyIsRecorded = array_filter($figures['sales'], static fn (array $row): bool => $row['currency'] !== null) !== [];

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'attribution' => $this->attribution(currencyIsRecorded: $currencyIsRecorded),
            'settlements' => $this->settlementsOf(rows: $this->figures->settlements(from: $window->startsAt(), before: $window->endsBefore())),
            'markets' => $this->marketsOf(
                selection: $selection,
                figures: $figures,
                alertCounts: $this->alerts->countsBySeverity(alerts: $this->alerts->forMarkets(selected: $selection->markets, stock: $figures['stock'], backlog: $figures['backlog'])),
            ),
            'unattributed' => $selection->includesUnattributed ? $this->figuresOf(market: null, figures: $figures) + ['reason' => self::NO_MARKET_REASON] : null,
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $figures
     * @param array<string, array{critical: int, warning: int, info: int}> $alertCounts
     *
     * @return list<array<string, mixed>>
     */
    private function marketsOf(MarketSelection $selection, array $figures, array $alertCounts): array
    {
        $overview = [];

        foreach ($this->markets->all() as $code => $market) {
            if (in_array($code, $selection->markets, true)) {
                $overview[] = ['market' => $code, 'storefront_currency' => $market['currency'], 'active' => $market['active']]
                    + $this->figuresOf(market: $code, figures: $figures)
                    + ['alerts' => $alertCounts[$code] ?? ['critical' => 0, 'warning' => 0, 'info' => 0]];
            }
        }

        return $overview;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $figures
     *
     * @return array<string, mixed>
     */
    private function figuresOf(?string $market, array $figures): array
    {
        $orders = $this->rowsOf(rows: $figures['orders'], market: $market);

        return [
            'orders' => [
                'total' => array_sum(array_column($orders, 'orders')),
                'by_status' => $this->byStatus(orders: $orders),
                'latest_at' => $this->latestOf(orders: $orders),
            ],
            'sales' => $this->salesOf(rows: $this->rowsOf(rows: $figures['sales'], market: $market)),
            'pending_fulfilment' => ['count' => array_sum(array_column($this->rowsOf(rows: $figures['backlog'], market: $market), 'orders'))],
            'returns' => $this->refundsOf(rows: $this->rowsOf(rows: $figures['refunds'], market: $market)),
            'stock' => $this->stockOf(rows: $this->rowsOf(rows: $figures['stock'], market: $market)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>> the rows of one market, or of no market when it is null
     */
    private function rowsOf(array $rows, ?string $market): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $row['market'] === $market));
    }

    /**
     * @param list<array<string, mixed>> $orders
     *
     * @return array<string, int> every status the platform knows, in its order, then any other the orders carry, by name
     */
    private function byStatus(array $orders): array
    {
        $known = array_fill_keys(getOrderStatusList(), 0);
        $others = [];

        foreach ($orders as $row) {
            if (array_key_exists($row['status'], $known)) {
                $known[$row['status']] += $row['orders'];
            } else {
                $others[$row['status']] = ($others[$row['status']] ?? 0) + $row['orders'];
            }
        }
        ksort($others);

        return $known + $others;
    }

    /** @param list<array<string, mixed>> $orders */
    private function latestOf(array $orders): ?string
    {
        $latest = array_filter(array_column($orders, 'latest_at'));

        return $latest === [] ? null : PlatformAdminPresenter::timestamp(moment: Carbon::parse(time: max($latest)));
    }

    /**
     * Sales of one market, per selling company and per currency, with the orders that record no currency counted apart.
     *
     * **`by_currency` holds only rows that name a currency.** There is no total over them and there will not be one: six markets' money is not
     * interchangeable, and a reader who wants one has to say which currency and at which rate, which is a question with an owner and a date on
     * it, not an arithmetic convenience. `not_counted` is the rest, with the reason -- and on today's platform that is every order, which is why
     * this answers what the overview answered before and adds the count it was missing.
     *
     * A company of null is an order that recorded none, reported as such rather than as the platform's own: "we do not know" and "the
     * platform's" are different answers and only one of them is true of an order placed before companies existed.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function salesOf(array $rows): array
    {
        $byCurrency = [];
        $notCounted = 0;

        foreach ($rows as $row) {
            if ($row['currency'] === null) {
                $notCounted += $row['orders'];
                continue;
            }

            $byCurrency[] = [
                'currency' => $row['currency'],
                'legal_entity_id' => $row['entity'],
                'orders' => $row['orders'],
                'amount' => $row['amount'],
            ];
        }

        if ($byCurrency === []) {
            // The platform of today, answered exactly as it was answered before this figure existed.
            return ['by_currency' => null, 'reason' => self::NO_CURRENCY_REASON];
        }

        usort($byCurrency, static fn (array $a, array $b): int => [$a['currency'], (int) $a['legal_entity_id']] <=> [$b['currency'], (int) $b['legal_entity_id']]);

        return [
            'by_currency' => $byCurrency,
            'not_counted' => ['orders' => $notCounted, 'reason' => $notCounted === 0 ? null : self::NO_CURRENCY_REASON],
        ];
    }

    /**
     * What the platform settled in the window, per company and per currency, and never across currencies (E-17).
     *
     * **It is NOT inside a market, and that is the honest shape rather than a convenience.** A settlement has no
     * market: it has a company, a currency and a period, and the shop it pays belongs to one company at a time. A
     * settlements figure filed under a market would be a claim nothing supports.
     *
     * An installation that keeps no settlements says so; one whose settlements record no currency says that
     * instead; and a settlement that records none is counted apart from the ones that do, never folded in.
     *
     * @param list<array{entity: ?int, currency: ?string, status: string, settlements: int, net: ?string}> $rows
     *
     * @return array<string, mixed>
     */
    private function settlementsOf(array $rows): array
    {
        if (!$this->figures->settlementsRecordTheirCurrency()) {
            return ['by_currency' => null, 'reason' => self::NO_SETTLEMENTS_REASON];
        }

        $byCurrency = [];
        $notCounted = 0;

        foreach ($rows as $row) {
            if ($row['currency'] === null) {
                $notCounted += $row['settlements'];
                continue;
            }

            $byCurrency[] = [
                'currency' => $row['currency'],
                'legal_entity_id' => $row['entity'],
                'status' => $row['status'],
                'settlements' => $row['settlements'],
                'net' => $row['net'],
            ];
        }

        usort($byCurrency, static fn (array $a, array $b): int => [$a['currency'], (int) $a['legal_entity_id'], $a['status']] <=> [$b['currency'], (int) $b['legal_entity_id'], $b['status']]);

        return [
            'by_currency' => $byCurrency === [] ? null : $byCurrency,
            'not_counted' => ['settlements' => $notCounted, 'reason' => $notCounted === 0 ? null : self::SETTLEMENT_CURRENCY_REASON],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{refund_requests: int, pending: int}
     */
    private function refundsOf(array $rows): array
    {
        return ['refund_requests' => array_sum(array_column($rows, 'total')), 'pending' => array_sum(array_column($rows, 'pending'))];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{zero: int, low: int}
     */
    private function stockOf(array $rows): array
    {
        return ['zero' => array_sum(array_column($rows, 'zero')), 'low' => array_sum(array_column($rows, 'low'))];
    }

    /** @return array<string, mixed> */
    private function attribution(bool $currencyIsRecorded): array
    {
        $attribution = [
            'orders' => ['rule' => 'all_lines_one_market', 'via' => 'order_details.product_id > products.storefront_country'],
            'refund_requests' => ['rule' => 'product_market', 'via' => 'refund_requests.product_id > products.storefront_country'],
            'stock' => ['rule' => 'live_physical_products_by_market', 'via' => 'products.storefront_country'],
            'products_country' => $this->figures->productsCountry(),
            'currency' => $currencyIsRecorded ? ['per_order' => true] : ['per_order' => false, 'reason' => self::NO_CURRENCY_REASON],
        ];

        if ($currencyIsRecorded) {
            $attribution['sales'] = ['rule' => 'per_selling_company_and_currency', 'never' => 'across_currencies', 'via' => 'orders.legal_entity_id, orders.currency_code'];
        }

        return $attribution;
    }
}
