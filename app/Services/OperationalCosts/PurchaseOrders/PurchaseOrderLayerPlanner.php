<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Models\InventoryCostLayer;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Schema;

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
 */
class PurchaseOrderLayerPlanner
{
    public const SOURCE_TYPE = 'purchase_order';

    private const OWN_COLUMNS = ['source_from', 'source_to', 'supplier_id', 'received_at_basis'];

    private const PAGE = 200;

    public function __construct(
        private readonly PlatformProcurement $procurement,
        private readonly PurchaseLineEvaluator $evaluator,
    ) {
    }

    /**
     * Every received line, in the order the layers are made in.
     *
     * @return Generator<int, LinePlan>
     */
    public function plans(?int $productId = null, ?int $lineId = null, ?CarbonImmutable $since = null): Generator
    {
        $after = 0;
        while (($lines = $this->procurement->receivedLines($after, self::PAGE, $productId, $lineId)) !== []) {
            $products = $this->procurement->products(array_map(static fn (PurchaseLine $line): ?int => $line->productId, $lines));
            $receipts = $this->procurement->receiptsByOrderAndProduct($lines);
            $accounted = $this->accountedUnits(array_map(static fn (PurchaseLine $line): int => $line->id, $lines));
            foreach ($lines as $line) {
                yield $this->plan($line, $products[$line->productId] ?? null, $receipts[$line->purchaseOrderId . ':' . $line->productId] ?? [], $accounted[$line->id] ?? 0, $since);
            }
            $after = $lines[array_key_last($lines)]->id;
        }
    }

    /** @return list<string> the columns of this module's layers that the purchase-order source writes and that are not there */
    private function missingOwnColumns(): array
    {
        return Schema::hasTable('inventory_cost_layers') ? array_values(array_diff(self::OWN_COLUMNS, Schema::getColumnListing('inventory_cost_layers'))) : self::OWN_COLUMNS;
    }

    /**
     * @param list<array{quantity: int, at: CarbonImmutable|null}> $receipts the receipts the platform's stock ledger holds for this line's order and product
     */
    private function plan(PurchaseLine $line, ?ProductFacts $product, array $receipts, int $accounted, ?CarbonImmutable $since): LinePlan
    {
        $verdict = $this->evaluator->verdict($line, $product);
        if ($line->quantityReceived < $accounted) {
            return new LinePlan($line, $verdict, $accounted, null, $accounted - $line->quantityReceived);
        }
        if (!$verdict->isEligible()) {
            return new LinePlan($line, $verdict, $accounted, null, 0);
        }

        $dated = $this->datedUnits($line, $receipts);
        $before = $since === null ? 0 : $this->unitsReceivedBefore($line, $dated, $since);
        if ($before === null) {
            return new LinePlan($line, LineVerdict::refused(PurchaseLineRefusal::DateUncertain), $accounted, null, 0);
        }

        $from = max($accounted, $before);
        $tranche = $from >= $line->quantityReceived ? null : new Tranche(
            from: $from,
            to: $line->quantityReceived,
            receivedAt: $dated === null ? ($line->updatedAt ?? CarbonImmutable::now()) : $dated[array_key_last($dated)]['at'],
            dateBasis: $dated === null ? Tranche::BASIS_LINE_DATE : Tranche::BASIS_MOVEMENTS,
        );

        return new LinePlan($line, $verdict, $accounted, $tranche, 0);
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
     * How many of the line's units came in before the cutover date; null when that cannot be told (the line was changed on or after the date and nothing dates its receipts).
     *
     * @param list<array{end: int, at: CarbonImmutable}>|null $dated
     */
    private function unitsReceivedBefore(PurchaseLine $line, ?array $dated, CarbonImmutable $since): ?int
    {
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
        if ($this->missingOwnColumns() !== []) {
            return [];
        }

        return InventoryCostLayer::query()->where('source_type', self::SOURCE_TYPE)->whereIn('source_id', $lineIds)
            ->selectRaw('source_id, max(source_to) as reaches')->groupBy('source_id')->pluck('reaches', 'source_id')
            ->map(static fn ($units): int => (int) $units)->all();
    }
}
