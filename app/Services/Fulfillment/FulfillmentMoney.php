<?php

namespace App\Services\Fulfillment;

use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Models\Order;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\MoneyAssessment;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\Fulfillment\Support\WhatACourierIsPaidIn;
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
        private readonly ProviderCurrency $currency,
    ) {
    }

    /**
     * @param Collection<int, ProviderFulfillmentItem> $shipmentItems this shipment's lines
     * @param Collection<int, ProviderFulfillmentItem> $allItems every non-cancelled provider line of the order
     */
    public function assess(Order $order, ProviderIdentity $provider, Collection $shipmentItems, Collection $allItems): MoneyAssessment
    {
        $plan = $this->codPolicy->plan($order, $allItems);
        $blockedReason = $plan->blockedReason
            ?? ($this->hasUnconvertedLine($shipmentItems) ? HoldReason::CURRENCY_UNCONVERTIBLE : null)
            ?? $this->holdIfTheStampWouldLie($order, $provider);
        $cod = $plan->codByProvider[$provider->code()] ?? 0.0;
        $decimals = $this->currency->decimals();
        $itemsTotal = round((float) $shipmentItems->sum('line_total'), $decimals);
        $total = $this->shares->totalFor($plan->orderTotal, $order->id, $shipmentItems, $allItems);

        return new MoneyAssessment(
            blockedReason: $blockedReason,
            paymentMode: $plan->isCod && $cod > 0 ? PaymentMode::CashOnDelivery : PaymentMode::Prepaid,
            codAmount: $plan->isCod ? $cod : 0.0,
            itemsTotal: $itemsTotal,
            shippingAllocation: $total === null ? 0.0 : round($total - $itemsTotal, $decimals),
            totalAmount: $total ?? $itemsTotal,
            decimals: $decimals,
        );
    }

    /**
     * Every figure in this assessment is in the currency {@see ProviderCurrency} converted into, which is the configured one. If the
     * shipment would be STAMPED with another currency -- because the owner has chosen one of the open options in
     * {@see WhatACourierIsPaidIn} and the converter does not follow it yet -- then the label and the figures disagree, and the figure
     * is the cash a courier collects from a customer. Hold it. Choosing an option therefore stops a shipment it would mislabel rather
     * than sending it mispriced, and the hold releases itself the day the converter answers in the same currency.
     *
     * With the default rule in force the two can never differ and this never fires.
     */
    private function holdIfTheStampWouldLie(Order $order, ProviderIdentity $provider): ?string
    {
        return WhatACourierIsPaidIn::forOrder($order, $provider->code()) === $this->currency->code()
            ? null
            : HoldReason::CURRENCY_UNCONVERTIBLE;
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
