<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;

/**
 * What a unit costs according to the platform's purchase orders: the cost of the latest received line that may be made into a layer, brought into the reporting currency. It is an
 * ESTIMATE basis (`last_purchase_order`) that ranks ahead of the product's own `purchase_price`, which the platform's own forms write as zero. Read-only, and used only while the
 * purchase-order source is on.
 */
class LastPurchaseOrderCost
{
    public const BASIS = 'last_purchase_order';

    /** How many of a product's most recently received lines are looked at before giving up: the newest ones that cannot be used (no cost, no rate) are skipped, not all of history. */
    private const LINES_LOOKED_AT = 5;

    public function __construct(
        private readonly PlatformProcurement $procurement,
        private readonly PurchaseLineEvaluator $evaluator,
        private readonly PurchaseOrderSchema $schema,
        private readonly FxConverter $fx,
    ) {
    }

    /** @return array{cost: Money, currency: string, basis: string}|null */
    public function forProduct(int $productId, string $variantKey, int $sellerId): ?array
    {
        if ($variantKey !== '' || $sellerId !== 0 || !PurchaseOrderSourceMode::active() || !$this->schema->ready()) {
            return null;
        }

        $lines = $this->procurement->latestReceivedLines($productId, self::LINES_LOOKED_AT);
        $product = $this->procurement->products([$productId])[$productId] ?? null;
        foreach ($lines as $line) {
            $verdict = $this->evaluator->verdict($line, $product);
            $converted = $verdict->isEligible() ? $this->fx->convert($verdict->unitCost, $verdict->currency, $this->fx->baseCurrency()) : null;
            if ($converted !== null) {
                return ['cost' => $converted['amount'], 'currency' => $this->fx->baseCurrency(), 'basis' => self::BASIS];
            }
        }

        return null;
    }
}
