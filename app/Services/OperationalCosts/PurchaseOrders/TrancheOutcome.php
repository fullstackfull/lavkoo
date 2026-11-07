<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Models\InventoryCostLayer;

/** What happened when the layer of one tranche was asked for: made now, already there (the same units), refused, or the line moved on under us. */
final readonly class TrancheOutcome
{
    public const CREATED = 'created';
    public const ALREADY_MADE = 'already_made';
    /** A layer for the same units' start exists but holds a different number of them: another run saw the line at another moment. The line is looked at again. */
    public const OVERTAKEN = 'overtaken';
    public const REFUSED = 'refused';

    private function __construct(
        public string $state,
        public ?InventoryCostLayer $layer = null,
        public ?PurchaseLineRefusal $refusal = null,
    ) {
    }

    public static function created(InventoryCostLayer $layer): self
    {
        return new self(self::CREATED, $layer);
    }

    public static function alreadyMade(InventoryCostLayer $layer): self
    {
        return new self(self::ALREADY_MADE, $layer);
    }

    public static function overtaken(InventoryCostLayer $layer): self
    {
        return new self(self::OVERTAKEN, $layer);
    }

    public static function refused(PurchaseLineRefusal $reason): self
    {
        return new self(self::REFUSED, refusal: $reason);
    }
}
