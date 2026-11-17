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
    ) {
    }

    /** @return array<string, mixed> */
    public function build(MarketSelection $selection, PlatformWindow $window): array
    {
        $figures = [
            'orders' => $this->figures->orders($window->startsAt(), $window->endsBefore()),
            'backlog' => $this->figures->backlog(),
            'refunds' => $this->figures->refundRequests($window->startsAt(), $window->endsBefore()),
            'stock' => $this->figures->stock(),
        ];

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'attribution' => $this->attribution(),
            'markets' => $this->marketsOf(
                $selection,
                $figures,
                $this->alerts->countsBySeverity($this->alerts->forMarkets($selection->markets, stock: $figures['stock'], backlog: $figures['backlog'])),
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
        $orders = $this->rowsOf($figures['orders'], $market);

        return [
            'orders' => [
                'total' => array_sum(array_column($orders, 'orders')),
                'by_status' => $this->byStatus($orders),
                'latest_at' => $this->latestOf($orders),
            ],
            'sales' => ['by_currency' => null, 'reason' => self::NO_CURRENCY_REASON],
            'pending_fulfilment' => ['count' => array_sum(array_column($this->rowsOf($figures['backlog'], $market), 'orders'))],
            'returns' => $this->refundsOf($this->rowsOf($figures['refunds'], $market)),
            'stock' => $this->stockOf($this->rowsOf($figures['stock'], $market)),
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

        return $latest === [] ? null : PlatformAdminPresenter::timestamp(Carbon::parse(max($latest)));
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
            'currency' => ['per_order' => false, 'reason' => self::NO_CURRENCY_REASON],
        ];
    }
}
