<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\ProviderCurrency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What one provider shipment is worth as part of the customer's parent order.
 *
 * An order's total is its lines plus everything that is not a line: shipping, a coupon, rounding. A provider
 * ships only some of the lines, so its shipment is worth its own lines plus a proportional share of that
 * remainder - pro rata by line value, never the whole order. With no in-house lines the providers' shares
 * add up to the order total to the cent (the same largest-remainder split cash on delivery uses); with
 * in-house lines the leftover belongs to the in-house part and is simply not allocated here.
 */
class ProviderOrderShare
{
    public function __construct(
        private readonly ProviderCurrency $currency,
        private readonly CodAllocator $allocator,
    ) {
    }

    /**
     * @param Collection<int, ProviderFulfillmentItem> $items this shipment's lines
     * @param Collection<int, ProviderFulfillmentItem> $allItems every non-cancelled provider line of the order
     */
    public function totalFor(?float $orderTotal, int $orderId, Collection $items, Collection $allItems): ?float
    {
        if ($orderTotal === null || $items->isEmpty()) {
            return null;
        }

        $itemsValue = (float) $items->sum('line_total');
        $providerValue = (float) $allItems->sum('line_total');
        $inHouseExists = DB::table('order_details')->where('order_id', $orderId)->count() > $allItems->count();

        if (!$inHouseExists) {
            $byProvider = $allItems->groupBy('provider')->map(static fn (Collection $lines): float => (float) $lines->sum('line_total'))->all();
            $shares = $this->allocator->allocate($orderTotal, $byProvider);

            return $shares[(string) $items->first()->provider] ?? round($itemsValue, 2);
        }

        $allLines = $this->allLinesValue($orderId);
        if ($allLines === null) {
            return null;
        }

        $denominator = max($allLines, $providerValue);

        return $denominator > 0
            ? round($itemsValue + ($orderTotal - $denominator) * ($itemsValue / $denominator), 2)
            : round($itemsValue, 2);
    }

    /** Every line of the order, in-house ones included, as the customer was charged for it, in the provider currency. */
    private function allLinesValue(int $orderId): ?float
    {
        $stored = DB::table('order_details')->where('order_id', $orderId)->get(['price', 'qty', 'discount', 'tax', 'tax_model'])
            ->sum(static fn (object $line): float => (float) $line->price * (int) $line->qty - (float) $line->discount
                + ($line->tax_model === 'exclude' ? (float) $line->tax : 0.0));

        return $this->currency->fromStored($stored);
    }
}
