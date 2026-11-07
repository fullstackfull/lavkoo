<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use Carbon\CarbonImmutable;

/**
 * The units of a line received since its last layer: units `from` + 1 to `to` of what the line has received. It is what one layer is made of, and `from` is what makes a second
 * layer for the same units impossible (the layer table is unique on the line and `from`).
 */
final readonly class Tranche
{
    public const BASIS_MOVEMENTS = 'stock_movement';
    public const BASIS_LINE_DATE = 'line_updated_at';

    public function __construct(
        public int $from,
        public int $to,
        public CarbonImmutable $receivedAt,
        public string $dateBasis,
    ) {
    }

    public function quantity(): int
    {
        return $this->to - $this->from;
    }

    /** True when the date is the line's last change, not the date of a receipt the stock ledger recorded. */
    public function dateIsApproximate(): bool
    {
        return $this->dateBasis === self::BASIS_LINE_DATE;
    }

    public function identity(int $lineId): string
    {
        return self::identityOf(lineId: $lineId, from: $this->from, to: $this->to);
    }

    /** The name of the layer of units `from` + 1 to `to` of a line (the layer's `dedupe_key` is made from it). */
    public static function identityOf(int $lineId, int $from, int $to): string
    {
        return "po-item:{$lineId}:{$from}-{$to}";
    }
}
