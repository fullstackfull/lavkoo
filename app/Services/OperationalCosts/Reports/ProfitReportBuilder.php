<?php

namespace App\Services\OperationalCosts\Reports;

use App\Enums\SalesChannels\SalesChannel;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Generator;
use InvalidArgumentException;

/**
 * Reads the sales of a period and hands them to the profit services, with a hard limit on how many it reads.
 *
 * It adds no arithmetic of its own: every figure comes from ProfitabilityService, ProfitAggregator and ProductProfitability. What it adds is the bound, because a
 * profit report over a long period is the one request on these screens that can read a store's whole history.
 */
class ProfitReportBuilder
{
    /** The most sales a report reads. */
    public const DOCUMENT_LIMIT = 5000;

    /** The most sales a by-product report reads: it looks at the cost entries of every sale one by one. */
    public const PRODUCT_DOCUMENT_LIMIT = 1000;

    public const DIMENSION_PRODUCT = 'product';

    public const DIMENSION_ORDER = 'order';

    public const DIMENSIONS = [self::DIMENSION_PRODUCT, self::DIMENSION_ORDER, ProfitAggregator::BY_CHANNEL, ProfitAggregator::BY_PROVIDER];

    public function __construct(
        private readonly SaleDocumentReader $documents,
        private readonly ProfitabilityService $profit,
        private readonly ProfitAggregator $aggregator,
        private readonly ProductProfitability $products,
        private readonly ProductNames $productNames,
    ) {
    }

    public static function limitFor(string $dimension): int
    {
        return $dimension === self::DIMENSION_PRODUCT ? self::PRODUCT_DOCUMENT_LIMIT : self::DOCUMENT_LIMIT;
    }

    public function build(ProfitFilters $filters, string $dimension): ProfitReport
    {
        $limit = self::limitFor($dimension);
        $documents = [];
        $truncated = false;
        foreach ($this->sales($filters) as $document) {
            if (!$this->matches($document, $filters)) {
                continue;
            }
            if (count($documents) >= $limit) {
                $truncated = true;
                break;
            }
            $documents[] = $document;
        }

        return new ProfitReport($filters, $documents, iterator_to_array($this->profit->forDocuments($documents), preserve_keys: false), $truncated, $limit);
    }

    /** The report along one dimension: by product, by channel or provider, or sale by sale. */
    public function table(ProfitReport $report, string $dimension): ProfitTable
    {
        if ($dimension === self::DIMENSION_ORDER) {
            return new ProfitTable(
                $dimension,
                array_map(ProfitLine::ofResult(...), $report->results),
                ProfitTotals::of($this->aggregator->group($report->results, ProfitAggregator::BY_CHANNEL)),
            );
        }

        $rows = $this->rows($report, $dimension);
        $isProduct = $dimension === self::DIMENSION_PRODUCT;
        $names = $isProduct ? $this->productNames->lookup(array_keys($rows)) : [];

        return new ProfitTable(
            $dimension,
            array_values(array_map(static fn (ProfitRow $row): ProfitLine => ProfitLine::ofRow($row, $names[(int) $row->key] ?? '', groupsSales: !$isProduct), $rows)),
            ProfitTotals::of($rows),
        );
    }

    /** @return array<string, ProfitRow> */
    private function rows(ProfitReport $report, string $dimension): array
    {
        return match ($dimension) {
            self::DIMENSION_PRODUCT => $this->products->rows($report->documents),
            ProfitAggregator::BY_CHANNEL, ProfitAggregator::BY_PROVIDER => $this->aggregator->group($report->results, $dimension),
            default => throw new InvalidArgumentException("'{$dimension}' is not a dimension a report can be grouped by."),
        };
    }

    /** @return Generator<int, SaleDocument> */
    private function sales(ProfitFilters $filters): Generator
    {
        $storefrontOnly = $filters->channel === SalesChannel::Storefront->value;
        $channelOrdersOnly = $filters->accountId !== null || ($filters->channel !== null && !$storefrontOnly);

        if (!$channelOrdersOnly) {
            yield from $this->documents->storeOrders($filters->from, $filters->to);
        }
        if (!$storefrontOnly) {
            yield from $this->documents->channelOrders($filters->from, $filters->to, $filters->channel, $filters->accountId);
        }
    }

    private function matches(SaleDocument $document, ProfitFilters $filters): bool
    {
        if ($filters->sellerId !== null && $document->sellerId !== $filters->sellerId) {
            return false;
        }
        if ($filters->provider === null) {
            return true;
        }

        return $filters->provider === ProfitFilters::NO_PROVIDER ? $document->provider === null : $document->provider === $filters->provider;
    }
}
