<?php

namespace App\Services\OperationalCosts;

use App\Services\OperationalCosts\Contributors\CostContributor;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Brings the cost ledger of a sale up to date by running every contributor listed in `operational_costs.contributors`.
 * Idempotent: running it for a sale that has not changed records nothing. One sale that cannot be brought up to date (a database refusal, bad data) is reported and counted
 * and never keeps the sales behind it from being looked at.
 */
class CostRefresher
{
    public function __construct(
        private readonly Container $container,
        private readonly SaleDocumentReader $documents,
    ) {
    }

    /** @return list<RecordOutcome> */
    public function refresh(SaleDocument $document): array
    {
        $outcomes = [];
        foreach ($this->contributors() as $contributor) {
            $outcomes = [...$outcomes, ...$contributor->contribute($document)];
        }

        return $outcomes;
    }

    /**
     * The sales placed in a period.
     *
     * @return array{documents: int, written: int, failed: int}
     */
    public function refreshPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->refreshAll([$this->documents->storeOrders($from, $to), $this->documents->channelOrders($from, $to)]);
    }

    /**
     * Every sale placed or changed since then: what the scheduler runs. A sale delivered, shipped or cancelled days after it was placed is brought up to date when it changes.
     *
     * @return array{documents: int, written: int, failed: int}
     */
    public function refreshChangedSince(CarbonImmutable $since): array
    {
        return $this->refreshAll([$this->documents->storeOrdersChangedSince($since), $this->documents->channelOrdersChangedSince($since)]);
    }

    /**
     * @param list<\Generator<int, SaleDocument>> $generators
     * @return array{documents: int, written: int, failed: int}
     */
    private function refreshAll(array $generators): array
    {
        $documents = 0;
        $written = 0;
        $failed = 0;
        foreach ($generators as $generator) {
            foreach ($generator as $document) {
                $documents++;
                try {
                    $written += count(array_filter($this->refresh($document), static fn (RecordOutcome $outcome): bool => $outcome->wroteSomething()));
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            }
        }

        return ['documents' => $documents, 'written' => $written, 'failed' => $failed];
    }

    /** @return list<CostContributor> */
    private function contributors(): array
    {
        return array_map(
            fn (string $class): CostContributor => $this->container->make($class),
            (array) config('operational_costs.contributors', []),
        );
    }
}
