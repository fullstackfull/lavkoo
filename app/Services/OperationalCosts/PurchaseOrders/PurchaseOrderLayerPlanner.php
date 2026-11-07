<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Models\InventoryCostLayer;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Works out, for every received line of the platform's purchase orders, what its cost layers would do. It only READS: the platform's tables, and the module's own layers to see
 * which units are already accounted for. Nothing is written, so the shadow mode and every report are this class and nothing else.
 *
 * A receipt is a cumulative quantity on a line (the platform keeps no row per receipt), so a line is made into layers one TRANCHE at a time: the units received since the last layer
 * (`from` is the units the earlier layers account for, `to` what the line has received). A line received 3 then 7 is two tranches, and received 10 at once it is one. A quantity that
 * falls below what was layered is reported, never reversed.
 *
 * A tranche is dated from the platform's stock ledger when the receipts it recorded for the order and product add up to exactly what the line received; otherwise the date is the
 * line's last change and the tranche is marked approximate. With a cutover date, units received before it are left to the opening balance and no layer is made for them.
 *
 * The first layer of a line is not made when an invoice or a hand-typed cost may already hold the same goods (see CoveringLayers) unless a person named the line as a separate purchase.
 */
class PurchaseOrderLayerPlanner
{
    public const SOURCE_TYPE = 'purchase_order';

    private const PAGE = 200;

    public function __construct(
        private readonly PlatformProcurement $procurement,
        private readonly PurchaseLineEvaluator $evaluator,
        private readonly PurchaseOrderSchema $schema,
        private readonly CoveringLayers $covering,
    ) {
    }

    /**
     * Every received line, in the order the layers are made in.
     *
     * @param list<int> $included the lines a person has said are separate purchases, whatever invoices and typed costs the module holds for the same product
     * @return Generator<int, LinePlan>
     */
    public function plans(?int $productId = null, ?int $lineId = null, ?CarbonImmutable $since = null, array $included = []): Generator
    {
        $after = 0;
        while (($lines = $this->procurement->receivedLines($after, self::PAGE, $productId, $lineId)) !== []) {
            $products = $this->procurement->products(array_map(static fn (PurchaseLine $line): ?int => $line->productId, $lines));
            $receipts = $this->procurement->receiptsByOrderAndProduct($lines);
            $accounted = $this->accountedUnits(array_map(static fn (PurchaseLine $line): int => $line->id, $lines));
            $covered = $this->covering->coveredLines(lines: array_values(array_filter($lines, static fn (PurchaseLine $line): bool => ($accounted[$line->id] ?? 0) === 0 && !in_array($line->id, $included, true))), since: $since);
            foreach ($lines as $line) {
                yield $this->plan($line, $products[$line->productId] ?? null, $receipts[$line->purchaseOrderId . ':' . $line->productId] ?? [], $accounted[$line->id] ?? 0, $since, isset($covered[$line->id]));
            }
            $after = $lines[array_key_last($lines)]->id;
        }
    }

    /**
     * @param list<array{quantity: int, at: CarbonImmutable|null}> $receipts the receipts the platform's stock ledger holds for this line's order and product
     */
    private function plan(PurchaseLine $line, ?ProductFacts $product, array $receipts, int $accounted, ?CarbonImmutable $since, bool $covered): LinePlan
    {
        $verdict = $this->evaluator->verdict($line, $product);
        if ($line->quantityReceived < $accounted) {
            return new LinePlan($line, $verdict, $accounted, null, $accounted - $line->quantityReceived);
        }
        if (!$verdict->isEligible() || $accounted >= $line->quantityReceived) {
            return new LinePlan($line, $verdict, $accounted, null, 0);
        }

        $dated = $this->datedUnits($line, $receipts);
        $before = $since === null ? 0 : $this->unitsReceivedBefore($line, $dated, $since);
        if ($before === null) {
            return new LinePlan($line, LineVerdict::refused(PurchaseLineRefusal::DateUncertain), $accounted, null, 0);
        }

        $from = max($accounted, $before);
        if ($from >= $line->quantityReceived) {
            return new LinePlan($line, $verdict, $accounted, null, 0);
        }
        if ($covered) {
            return new LinePlan($line, LineVerdict::refused(PurchaseLineRefusal::CoveredByOtherLayers), $accounted, null, 0);
        }

        return new LinePlan($line, $verdict, $accounted, new Tranche(
            from: $from,
            to: $line->quantityReceived,
            receivedAt: $dated === null ? ($line->updatedAt ?? CarbonImmutable::now()) : $dated[array_key_last($dated)]['at'],
            dateBasis: $dated === null ? Tranche::BASIS_LINE_DATE : Tranche::BASIS_MOVEMENTS,
        ), 0);
    }

    /**
     * The receipts with the running total of units, when the stock ledger accounts for exactly what the line has received and every entry has a date. The platform logs a receipt
     * against the order, so a line whose figures do not add up (a receipt the log missed, two lines of one order for one product) is not dated from it.
     *
     * @param list<array{quantity: int, at: CarbonImmutable|null}> $receipts
     * @return list<array{end: int, at: CarbonImmutable}>|null
     */
    private function datedUnits(PurchaseLine $line, array $receipts): ?array
    {
        $running = 0;
        $dated = [];
        foreach ($receipts as $receipt) {
            if ($receipt['at'] === null || $receipt['quantity'] < 1) {
                return null;
            }
            $running += $receipt['quantity'];
            $dated[] = ['end' => $running, 'at' => $receipt['at']];
        }

        return $dated !== [] && $running === $line->quantityReceived ? $dated : null;
    }

    /**
     * How many of the line's units came in before the cutover date; null when that cannot be told (the line existed before the date, was changed on or after it, and nothing dates
     * its receipts). A line made on or after the date received nothing before it.
     *
     * @param list<array{end: int, at: CarbonImmutable}>|null $dated
     */
    private function unitsReceivedBefore(PurchaseLine $line, ?array $dated, CarbonImmutable $since): ?int
    {
        if ($line->createdAt !== null && $line->createdAt >= $since) {
            return 0;
        }
        if ($dated === null) {
            return $line->updatedAt !== null && $line->updatedAt < $since ? $line->quantityReceived : null;
        }
        $units = 0;
        foreach ($dated as $receipt) {
            if ($receipt['at'] < $since) {
                $units = $receipt['end'];
            }
        }

        return $units;
    }

    /**
     * The units each line's purchase-order layers account for: how far their tranches reach.
     *
     * @param list<int> $lineIds
     * @return array<int, int>
     */
    private function accountedUnits(array $lineIds): array
    {
        if ($this->schema->missingOwnColumns() !== []) {
            return [];
        }

        return InventoryCostLayer::query()->where('source_type', self::SOURCE_TYPE)->whereIn('source_id', $lineIds)
            ->selectRaw('source_id, max(source_to) as reaches')->groupBy('source_id')->pluck('reaches', 'source_id')
            ->map(static fn ($units): int => (int) $units)->all();
    }
}
