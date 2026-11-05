<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\CollectionPlan;
use App\Services\Fulfillment\Support\ProviderCurrency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash on delivery across providers. The rule that protects money:
 *
 *   One collector  → that collector collects everything the customer still owes.
 *   Several        → HELD, unless mixed COD is enabled in the environment AND every provider involved is
 *                    marked `cod_verified` AND no in-house delivery shares the order. Then the amount is
 *                    split pro rata by shipped value, summing exactly to what is owed.
 *
 * The in-house flow books the WHOLE `order_amount` as the delivery man's cash in hand, so an order that
 * is part in-house, part provider can never be split safely and is always held.
 */
class CodPolicy
{
    public function __construct(
        private readonly ProviderCurrency $currency,
        private readonly CodAllocator $allocator,
        private readonly ProviderConnectionManager $connections,
    ) {
    }

    /** @param Collection<int, ProviderFulfillmentItem> $providerItems every non-cancelled provider item of the order */
    public function plan(Order $order, Collection $providerItems): CollectionPlan
    {
        $orderTotal = $this->currency->fromStored($order->order_amount);
        $isCod = $order->payment_method === 'cash_on_delivery' && $order->payment_status !== 'paid';
        $providers = $providerItems->pluck('provider')->unique()->values()->all();
        $zero = array_fill_keys($providers, 0.0);

        if ($orderTotal === null) {
            return new CollectionPlan($isCod, HoldReason::CURRENCY_UNCONVERTIBLE, $zero, null);
        }
        if (!$isCod) {
            return new CollectionPlan(false, null, $zero, $orderTotal);
        }

        $owed = $this->currency->fromStored(max(0.0, (float) $order->order_amount - (float) $order->paid_amount));
        if ($owed === null) {
            return new CollectionPlan(true, HoldReason::CURRENCY_UNCONVERTIBLE, $zero, $orderTotal);
        }

        $hasInHouse = DB::table('order_details')->where('order_id', $order->id)->count() > $providerItems->count();
        $collectors = count($providers) + ($hasInHouse ? 1 : 0);

        if ($collectors <= 1) {
            return new CollectionPlan(true, null, [$providers[0] => $owed], $orderTotal);
        }

        if (!$this->mixedCodAllowed($providers, $hasInHouse)) {
            return new CollectionPlan(true, HoldReason::MIXED_COD_BLOCKED, $zero, $orderTotal);
        }

        $valueByProvider = [];
        foreach ($providers as $provider) {
            $valueByProvider[$provider] = (float) $providerItems->where('provider', $provider)->sum('line_total');
        }

        $allocation = $this->allocator->allocate($owed, $valueByProvider);

        return $allocation === null
            ? new CollectionPlan(true, HoldReason::MIXED_COD_BLOCKED, $zero, $orderTotal)
            : new CollectionPlan(true, null, $allocation, $orderTotal);
    }

    /** @param list<string> $providers */
    private function mixedCodAllowed(array $providers, bool $hasInHouse): bool
    {
        if ($hasInHouse || !config('fulfillment.cod.allow_mixed', false)) {
            return false;
        }

        foreach ($providers as $provider) {
            $code = ProviderCode::tryFrom($provider);
            if ($code === null || !$this->connections->config($code)->bool('cod_verified')) {
                return false;
            }
        }

        return true;
    }
}
