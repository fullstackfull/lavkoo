<?php

namespace App\Services\PlatformAdmin;

/**
 * One market's own summary over a bounded window (L-14): its orders by status, its returns, what it sold and what was settled with its shops, each by
 * the company and the currency and never one figure across two.
 *
 * It is a REPORT and not the overview. The overview answers every market the operator may see, in counts, so that a phone shows six cards; a report
 * answers ONE market and adds the money -- what it sold by company and currency, and what was settled with its shops -- which is the part a finance
 * reader needs and the part that must never be added up across currencies. The two share their figures (`PlatformMarketFigures`) rather than
 * counting anything a second way.
 *
 * Nothing is ever summed across currencies here. A combined figure exists only with the rate, the source and the date that justify it
 * (`PlatformSalesCombination`), and the configuration that would allow one ships empty.
 */
final class PlatformReportService
{
    private const NO_CURRENCY_REASON = 'order_currency_not_recorded';

    public function __construct(
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformSettlementFigures $settlements,
        private readonly PlatformLegalEntities $entities,
        private readonly PlatformSalesCombination $combination,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(string $market, PlatformWindow $window): array
    {
        $company = $this->entities->of(market: $market);

        return [
            'market' => $market,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'legal_entity' => $company === null
                ? ['id' => null, 'name' => null, 'reason' => 'no_company_recorded_for_this_market']
                : ['id' => $company['id'], 'name' => $company['name'], 'trade_name' => $company['trade_name']],
            'orders' => $this->ordersOf(market: $market, window: $window),
            'returns' => $this->returnsOf(market: $market, window: $window),
            'sales' => $this->salesOf(market: $market, window: $window),
            'settlements' => $this->settlementsOf(market: $market, window: $window),
        ];
    }

    /** @return array{total: int, by_status: array<string, int>} */
    private function ordersOf(string $market, PlatformWindow $window): array
    {
        $rows = $this->ofMarket(rows: $this->figures->orders(from: $window->startsAt(), before: $window->endsBefore()), market: $market);
        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + $row['orders'];
        }
        ksort($byStatus);

        return ['total' => array_sum(array_column($rows, 'orders')), 'by_status' => $byStatus];
    }

    /** @return array{refund_requests: int, pending: int} */
    private function returnsOf(string $market, PlatformWindow $window): array
    {
        $rows = $this->ofMarket(rows: $this->figures->refundRequests(from: $window->startsAt(), before: $window->endsBefore()), market: $market);

        return ['refund_requests' => array_sum(array_column($rows, 'total')), 'pending' => array_sum(array_column($rows, 'pending'))];
    }

    /** @return array<string, mixed> */
    private function salesOf(string $market, PlatformWindow $window): array
    {
        if (!$this->figures->orderCurrencyRecorded()) {
            return ['by_currency' => null, 'reason' => self::NO_CURRENCY_REASON];
        }

        $rows = $this->ofMarket(rows: $this->figures->sales(from: $window->startsAt(), before: $window->endsBefore()), market: $market);
        $priced = array_values(array_filter($rows, static fn (array $row): bool => $row['currency'] !== null));
        usort($priced, static fn (array $one, array $other): int => [$one['currency'], $one['entity_id']] <=> [$other['currency'], $other['entity_id']]);
        $combined = $this->combination->combine(rows: $priced);

        return [
            'by_currency' => array_map(fn (array $row): array => [
                'currency' => $row['currency'],
                'legal_entity_id' => $row['entity_id'],
                'legal_entity' => $this->entities->byId($row['entity_id'])['name'] ?? null,
                'orders' => $row['orders'],
                'gross' => $row['gross'],
            ], $priced),
            'unpriced' => ['orders' => array_sum(array_column(array_filter($rows, static fn (array $row): bool => $row['currency'] === null), 'orders')), 'reason' => self::NO_CURRENCY_REASON],
            'combined' => $combined['figure'],
            'reason' => $combined['reason'],
        ];
    }

    /** @return array<string, mixed> */
    private function settlementsOf(string $market, PlatformWindow $window): array
    {
        if (!$this->settlements->recorded()) {
            return ['by_currency' => null, 'reason' => PlatformSettlementFigures::NOT_RECORDED];
        }

        return ['by_currency' => $this->settlements->forMarket(market: $market, window: $window), 'reason' => null];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function ofMarket(array $rows, string $market): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $row['market'] === $market));
    }
}
