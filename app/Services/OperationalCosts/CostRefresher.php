<?php

namespace App\Services\OperationalCosts;

use App\Services\OperationalCosts\Contributors\CostContributor;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Brings the cost ledger of a sale up to date by running every contributor listed in `operational_costs.contributors`.
 * Idempotent: running it for a sale that has not changed records nothing.
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

    /** @return array{documents: int, written: int} */
    public function refreshPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $documents = 0;
        $written = 0;
        $generators = [
            $this->documents->storeOrders($from, $to),
            $this->documents->channelOrders($from, $to),
        ];
        foreach ($generators as $generator) {
            foreach ($generator as $document) {
                $documents++;
                $written += count(array_filter($this->refresh($document), static fn (RecordOutcome $outcome): bool => $outcome->wroteSomething()));
            }
        }

        return ['documents' => $documents, 'written' => $written];
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
