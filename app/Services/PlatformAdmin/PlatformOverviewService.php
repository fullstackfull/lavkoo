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
 * Sales are reported only once an order records the currency its amounts are in, and never across two currencies; an installation whose orders
 * record none says why there is no figure instead (see salesOf below). The stock figures include the two dormant halves of the inventory stack --
 * what is held for orders and how a pooled stock is divided -- which report a reason rather than a zero while their tables do not exist.
 */
final class PlatformOverviewService
{
    private const NO_MARKET_REASON = 'no_single_market';

    private const NO_CURRENCY_REASON = 'order_currency_not_recorded';

    private const NO_SETTLEMENTS_REASON = 'no_settlements_are_recorded_on_this_installation';

    private const SETTLEMENT_CURRENCY_REASON = 'settlement_currency_not_recorded';

    private const NO_INVENTORY_REASON = 'inventory_stack_is_not_installed';

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
            'reservations' => $this->figures->reservations(),
            'allocations' => $this->figures->allocations(),
            'sales' => $this->figures->sales(from: $window->startsAt(), before: $window->endsBefore()),
        ];

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'attribution' => $this->attribution(),
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
            'stock' => $this->stockOf(
                rows: $this->rowsOf(rows: $figures['stock'], market: $market),
                held: $this->rowsOf(rows: $figures['reservations'], market: $market),
                pooled: $this->rowsOf(rows: $figures['allocations'], market: $market),
            ),
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
     * The stock of a market: how many live products are at zero or low, how many went PAST zero and by how many units, what is promised to orders
     * and not yet shipped, and how the division of a pooled stock between channels stands.
     *
     * `oversold` is the sharper reading of rows `zero` already counts, so the two overlap on purpose (PlatformMarketFigures::stock).
     *
     * `reserved` and `allocation` are `null` and NOT zero on an installation that keeps neither -- the dormant half of the inventory stack, whose
     * migration has not run. "No stock is held here" and "this platform does not record held stock" are different facts, and a zero would say the
     * first while meaning the second. `attribution.inventory` carries the reason once, because it is a fact about the installation and not about a
     * market.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $held
     * @param list<array<string, mixed>> $pooled
     *
     * @return array<string, mixed>
     */
    private function stockOf(array $rows, array $held, array $pooled): array
    {
        return [
            'zero' => array_sum(array_column($rows, 'zero')),
            'low' => array_sum(array_column($rows, 'low')),
            'oversold' => array_sum(array_column($rows, 'oversold')),
            'oversold_units' => array_sum(array_column($rows, 'oversold_units')),
            'reserved' => $this->figures->reservationsRecorded()
                ? ['holds' => array_sum(array_column($held, 'holds')), 'units' => array_sum(array_column($held, 'units'))]
                : null,
            'allocation' => $this->figures->allocationsRecorded()
                ? [
                    'pools' => array_sum(array_column($pooled, 'pools')),
                    'short' => array_sum(array_column($pooled, 'short')),
                    'short_units' => array_sum(array_column($pooled, 'short_units')),
                ]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function attribution(): array
    {
        return [
            'orders' => PlatformMarketFigures::ORDER_ATTRIBUTION,
            'refund_requests' => ['rule' => 'product_market', 'via' => 'refund_requests.product_id > products.storefront_country'],
            'stock' => ['rule' => 'live_physical_products_by_market', 'via' => 'products.storefront_country'],
            'inventory' => $this->inventoryAttribution(),
            'products_country' => $this->figures->productsCountry(),
            'currency' => $this->figures->orderCurrencyRecorded()
                ? ['per_order' => true, 'rule' => 'order_market_company_and_currency', 'via' => 'orders.storefront_country, orders.legal_entity_id, orders.currency_code', 'priced_when' => 'currency_rate = 1']
                : ['per_order' => false, 'reason' => self::NO_CURRENCY_REASON],
        ];
    }

    /**
     * Where the held stock and the pooled stock of a market come from, and whether this installation records either at all. Each half is reported
     * on its own because the two tables arrive in two migrations and an installation can have run one and not the other.
     *
     * @return array<string, mixed>
     */
    private function inventoryAttribution(): array
    {
        $holds = $this->figures->reservationsRecorded();
        $pools = $this->figures->allocationsRecorded();

        return [
            'reservations' => $holds ? ['rule' => 'product_market', 'via' => 'inventory_reservations.product_id > products.storefront_country', 'counted_when' => 'state = reserved'] : null,
            'allocation' => $pools ? ['rule' => 'product_market', 'via' => 'inventory_pools.product_id > products.storefront_country', 'short_when' => 'deficit > 0'] : null,
            'reason' => $holds && $pools ? null : self::NO_INVENTORY_REASON,
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
}
