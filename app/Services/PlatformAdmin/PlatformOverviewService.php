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
 * Sales are not reported. An order stores no currency, and the amounts it stores are in the platform's base currency, whatever the market
 * it was sold in; adding them up under a market's currency, or under any, would be a guess, so the answer says why there is no figure.
 */
final class PlatformOverviewService
{
    private const NO_MARKET_REASON = 'no_single_market';

    private const NO_CURRENCY_REASON = 'order_currency_not_recorded';

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformAlertService $alerts,
        private readonly PlatformLegalEntities $entities,
        private readonly PlatformSalesCombination $combination,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(MarketSelection $selection, PlatformWindow $window): array
    {
        $figures = [
            'orders' => $this->figures->orders(from: $window->startsAt(), before: $window->endsBefore()),
            'backlog' => $this->figures->backlog(),
            'refunds' => $this->figures->refundRequests(from: $window->startsAt(), before: $window->endsBefore()),
            'stock' => $this->figures->stock(),
            'sales' => $this->figures->sales(from: $window->startsAt(), before: $window->endsBefore()),
        ];

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'attribution' => $this->attribution(),
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
     * What a market sold, by the company that sold it and the currency of its own amounts, never one figure across two currencies.
     *
     * While an order records no currency at all -- the migration of GCC-05 has not run on this installation -- the answer is what it has always
     * been: no figure, and the reason. Once orders do record one, each currency is its own row with the company that sold it, orders whose
     * currency cannot be honoured are counted apart with no amount, and a figure across currencies exists only if the owner has written down a
     * conversion rule, in which case it carries that rule's rate, source and date (PlatformSalesCombination).
     *
     * @param list<array<string, mixed>> $rows the sales rows of this market
     *
     * @return array<string, mixed>
     */
    private function salesOf(array $rows): array
    {
        if (!$this->figures->orderCurrencyRecorded()) {
            return ['by_currency' => null, 'reason' => self::NO_CURRENCY_REASON];
        }

        $priced = array_values(array_filter($rows, static fn (array $row): bool => $row['currency'] !== null));
        // By currency and then by company, so the answer reads the same whatever order the engine grouped the rows in.
        usort($priced, static fn (array $one, array $other): int => [$one['currency'], $one['entity_id']] <=> [$other['currency'], $other['entity_id']]);
        $unpriced = array_values(array_filter($rows, static fn (array $row): bool => $row['currency'] === null));
        $combined = $this->combination->combine(rows: $priced);

        return [
            'by_currency' => array_map(fn (array $row): array => [
                'currency' => $row['currency'],
                'legal_entity_id' => $row['entity_id'],
                'legal_entity' => $this->entities->byId($row['entity_id'])['name'] ?? null,
                'orders' => $row['orders'],
                'gross' => $row['gross'],
            ], $priced),
            'unpriced' => ['orders' => array_sum(array_column($unpriced, 'orders')), 'reason' => self::NO_CURRENCY_REASON],
            'combined' => $combined['figure'],
            'reason' => $combined['reason'],
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
    private function attribution(): array
    {
        return [
            'orders' => ['rule' => 'all_lines_one_market', 'via' => 'order_details.product_id > products.storefront_country'],
            'refund_requests' => ['rule' => 'product_market', 'via' => 'refund_requests.product_id > products.storefront_country'],
            'stock' => ['rule' => 'live_physical_products_by_market', 'via' => 'products.storefront_country'],
            'products_country' => $this->figures->productsCountry(),
            'currency' => $this->figures->orderCurrencyRecorded()
                ? ['per_order' => true, 'rule' => 'order_market_company_and_currency', 'via' => 'orders.storefront_country, orders.legal_entity_id, orders.currency_code', 'priced_when' => 'currency_rate = 1']
                : ['per_order' => false, 'reason' => self::NO_CURRENCY_REASON],
        ];
    }
}
