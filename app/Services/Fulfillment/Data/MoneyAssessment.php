<?php

namespace App\Services\Fulfillment\Data;

use App\Enums\Fulfillment\PaymentMode;
use App\Models\ProviderFulfillment;

/**
 * What one provider shipment is worth and who collects what for it, judged against the order as it stands NOW.
 *
 * `blockedReason` set means the money cannot be assigned safely right now (a cash split that is not enabled and
 * verified, an amount that cannot be converted): the shipment must be held, not sent.
 */
final readonly class MoneyAssessment
{
    public function __construct(
        public ?string $blockedReason,
        public PaymentMode $paymentMode,
        public float $codAmount,
        public float $itemsTotal,
        public float $shippingAllocation,
        public float $totalAmount,
        public int $decimals,
    ) {
    }

    /** @return array<string, mixed> the fulfillment columns this assessment decides */
    public function columns(): array
    {
        return [
            'payment_mode' => $this->paymentMode,
            'cod_amount' => $this->codAmount,
            'items_total' => $this->itemsTotal,
            'shipping_allocation' => $this->shippingAllocation,
            'total_amount' => $this->totalAmount,
        ];
    }

    public function differsFrom(ProviderFulfillment $fulfillment): bool
    {
        return $fulfillment->payment_mode !== $this->paymentMode
            || !$this->sameAmount($fulfillment->cod_amount, $this->codAmount)
            || !$this->sameAmount($fulfillment->items_total, $this->itemsTotal)
            || !$this->sameAmount($fulfillment->shipping_allocation, $this->shippingAllocation)
            || !$this->sameAmount($fulfillment->total_amount, $this->totalAmount);
    }

    /** @return array<string, string|float> the figures that changed, "old → new", for the audit trail */
    public function changesTo(ProviderFulfillment $fulfillment): array
    {
        $changes = [];
        foreach ($this->columns() as $column => $new) {
            $old = $fulfillment->{$column};
            $oldValue = $old instanceof PaymentMode ? $old->value : (float) $old;
            $newValue = $new instanceof PaymentMode ? $new->value : (float) $new;
            if ($oldValue !== $newValue) {
                $changes[$column] = $oldValue . ' → ' . $newValue;
            }
        }

        return $changes;
    }

    /**
     * Two figures are the same figure when they are closer than half the smallest coin of the currency.
     *
     * Half a halala for a riyal, which is what this has always compared; half a fils for the dinar and the rial, because a tolerance of
     * half a halala would call a shipment that owes four fils more than the record says unchanged, and the provider would keep collecting
     * the old figure (C-15).
     */
    private function sameAmount(mixed $stored, float $assessed): bool
    {
        return abs((float) $stored - $assessed) < 0.5 / (10 ** max(0, $this->decimals));
    }
}
