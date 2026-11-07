<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

/**
 * What one pass over the received purchase-order lines found (and, when it was allowed to write, did). A preview fills the `planned` figures and writes nothing; an applied run
 * also fills `created`.
 */
final class LayerRun
{
    public int $lines = 0;

    /** Lines whose layers already account for everything received. */
    public int $upToDate = 0;

    public int $planned = 0;

    public int $plannedUnits = 0;

    public int $created = 0;

    public int $createdUnits = 0;

    /** Tranches whose layer was already there when it was asked for: another run made it between this one reading the line and writing it, or it was made at another location. */
    public int $alreadyMade = 0;

    /** Lines whose received quantity fell below the units their layers account for: reported, never reversed. */
    public int $receivedBelowLayered = 0;

    /** Layers whose date is the line's last change because the stock ledger does not account for the line's receipts. */
    public int $approximateDates = 0;

    /** @var array<string, int> lines by the reason they cannot be layered */
    public array $refused = [];

    /** @var list<array<string, mixed>> the layers (to be) made, the first few */
    public array $tranches = [];

    public function __construct(private readonly int $sample = 50)
    {
    }

    /** @param array<string, mixed> $row */
    public function remember(array $row): void
    {
        if (count($this->tranches) < $this->sample) {
            $this->tranches[] = $row;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'up_to_date' => $this->upToDate,
            'planned_layers' => $this->planned,
            'planned_units' => $this->plannedUnits,
            'created_layers' => $this->created,
            'created_units' => $this->createdUnits,
            'already_made' => $this->alreadyMade,
            'received_below_layered' => $this->receivedBelowLayered,
            'approximate_dates' => $this->approximateDates,
            'refused' => $this->refused,
            'layers' => $this->tranches,
        ];
    }
}
