<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\MoneyAssessment;
use Illuminate\Support\Collection;

/**
 * The one place that decides what a provider shipment is worth and how much cash its provider collects.
 *
 * Asked when the shipment is created, when a money hold is re-evaluated, and again just before it is sent: the order
 * can be paid, part-paid or have its cash-collection rules changed in the gap between routing and dispatch, and a
 * provider told to collect cash that the customer has already paid would collect it a second time.
 */
class FulfillmentMoney
{
    public function __construct(
        private readonly CodPolicy $codPolicy,
        private readonly ProviderOrderShare $shares,
    ) {
    }

    /**
     * @param Collection<int, ProviderFulfillmentItem> $shipmentItems this shipment's lines
     * @param Collection<int, ProviderFulfillmentItem> $allItems every non-cancelled provider line of the order
     */
    public function assess(Order $order, ProviderCode $provider, Collection $shipmentItems, Collection $allItems): MoneyAssessment
    {
        $plan = $this->codPolicy->plan($order, $allItems);
        $blockedReason = $plan->blockedReason ?? ($this->hasUnconvertedLine($shipmentItems) ? HoldReason::CURRENCY_UNCONVERTIBLE : null);
        $cod = $plan->codByProvider[$provider->value] ?? 0.0;
        $itemsTotal = round((float) $shipmentItems->sum('line_total'), 2);
        $total = $this->shares->totalFor($plan->orderTotal, $order->id, $shipmentItems, $allItems);

        return new MoneyAssessment(
            blockedReason: $blockedReason,
            paymentMode: $plan->isCod && $cod > 0 ? PaymentMode::CashOnDelivery : PaymentMode::Prepaid,
            codAmount: $plan->isCod ? $cod : 0.0,
            itemsTotal: $itemsTotal,
            shippingAllocation: $total === null ? 0.0 : round($total - $itemsTotal, 2),
            totalAmount: $total ?? $itemsTotal,
        );
    }

    /**
     * A line captured while its amount could not be converted was frozen with a total of 0 (its snapshot says so). Fixing the rate later
     * does not give it its value back, so a shipment holding such a line stays held rather than go out priced at nothing.
     *
     * @param Collection<int, ProviderFulfillmentItem> $items
     */
    private function hasUnconvertedLine(Collection $items): bool
    {
        return $items->contains(static fn (ProviderFulfillmentItem $item): bool => ($item->snapshot['currency_converted'] ?? true) === false);
    }

    /** @return Collection<int, ProviderFulfillmentItem> every non-cancelled provider line of the order */
    public function allItems(int $orderId): Collection
    {
        return ProviderFulfillmentItem::where('order_id', $orderId)
            ->where('status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
            ->get();
    }
}
