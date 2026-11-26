<?php

namespace App\Services\PlatformAdmin;

/**
 * One market's own summary over a bounded window (L-13, L-14): its orders by status, its returns, what it sold, what it TOOK, the TAX it recorded and
 * what was settled with its shops, each by the company and the currency and never one figure across two.
 *
 * It is a REPORT and not the overview. The overview answers every market the operator may see, in counts, so that a phone shows six cards; a report
 * answers ONE market and adds the money -- what it sold by company and currency, and what was settled with its shops -- which is the part a finance
 * reader needs and the part that must never be added up across currencies. The two share their figures (`PlatformMarketFigures`) rather than
 * counting anything a second way.
 *
 * Nothing is ever summed across currencies here. A combined figure exists only with the rate, the source and the date that justify it
 * (`PlatformSalesCombination`), and the configuration that would allow one ships empty.
 *
 * **Four money figures of one market, and why they are four and not one.** What it SOLD is what was billed; what it TOOK is what arrived, by the method
 * it arrived through; its TAX is what the authority is owed out of that; what was SETTLED is what then left for its shops. They answer different
 * questions and are never reconciled into a single number here -- an order billed inside the window and paid after it makes the first two differ
 * legitimately, and a reader who needs them to agree needs the dates, not a sum.
 */
final class PlatformReportService
{
    private const NO_CURRENCY_REASON = 'order_currency_not_recorded';

    private const NO_PAYMENT_REASON = 'order_payment_not_recorded';

    private const NO_TAX_REASON = 'order_tax_not_recorded';

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
            'payments' => $this->paymentsOf(market: $market, window: $window),
            'tax' => $this->taxOf(market: $market, window: $window),
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

    /**
     * What the market took, by method and currency.
     *
     * A method of its own for a method the order does not record, and no amount at all on a row whose currency is not the market's own money: the
     * figures say both rather than making one number out of orders that cannot be added.
     *
     * @return array<string, mixed>
     */
    private function paymentsOf(string $market, PlatformWindow $window): array
    {
        if (!$this->figures->orderPaymentRecorded()) {
            return ['by_method' => null, 'reason' => self::NO_PAYMENT_REASON];
        }

        return [
            'by_method' => $this->figures->paymentsOfMarket(market: $market, from: $window->startsAt(), before: $window->endsBefore()),
            'reason' => null,
        ];
    }

    /**
     * The tax the market recorded: what was charged on top, and what was already inside a tax-included price, never added together.
     *
     * The company that sold each row is named from the registry, as the sales rows are, so a reader sees whose books the tax belongs in.
     *
     * @return array<string, mixed>
     */
    private function taxOf(string $market, PlatformWindow $window): array
    {
        if (!$this->figures->orderTaxRecorded()) {
            return ['by_currency' => null, 'reason' => self::NO_TAX_REASON];
        }

        $rows = $this->figures->taxOfMarket(market: $market, from: $window->startsAt(), before: $window->endsBefore());

        return [
            'by_currency' => array_map(fn (array $row): array => [
                'currency' => $row['currency'],
                'legal_entity_id' => $row['legal_entity_id'],
                'legal_entity' => $this->entities->byId($row['legal_entity_id'])['name'] ?? null,
                'orders' => $row['orders'],
                'charged' => $row['charged'],
                'included' => $row['included'],
                'tax_included_orders' => $row['tax_included_orders'],
                'included_not_recorded' => $row['included_not_recorded'],
            ], $rows),
            'included_recorded' => $this->figures->includedTaxRecorded(),
            'reason' => null,
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
