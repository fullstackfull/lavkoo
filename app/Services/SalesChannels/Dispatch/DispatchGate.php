<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\RoutingAction;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\DB;

/**
 * Every condition for turning one channel order into a provider shipment that can be judged WITHOUT reading anything about the buyer and without calling anyone.
 * The provider's own switches (integrations, dry run, approvals, readiness, the Torod invoice proof) are not repeated here: they apply to the internal order's shipment unchanged
 * and are the Fulfillment module's to enforce.
 */
class DispatchGate
{
    public function __construct(private readonly ProviderCurrency $currency)
    {
    }

    public function evaluate(ExternalOrderMapping $mapping): DispatchVerdict
    {
        $mapping->loadMissing('account', 'lines');
        $blockers = [...$this->switches(), ...$this->account($mapping), ...$this->order($mapping)];

        $decision = FulfillmentRoutingDecision::where('external_order_mapping_id', $mapping->id)->where('is_current', true)->first();
        $provider = $decision?->action === RoutingAction::RouteToProvider->value ? ProviderCode::tryFrom((string) $decision->provider)?->value : null;
        if ($provider === null) {
            $blockers[] = 'no_routing_decision_to_one_provider';
        } else {
            $blockers = [...$blockers, ...$this->lines($mapping, $provider)];
        }

        return new DispatchVerdict(array_values(array_unique($blockers)), $provider);
    }

    /** @return list<string> */
    private function switches(): array
    {
        return array_values(array_filter([
            config('sales_channels.managed_dispatch_enabled', false) ? null : 'managed_dispatch_off',
            config('sales_channels.buyer_data_transfer_enabled', false) ? null : 'buyer_data_transfer_off',
        ]));
    }

    /** @return list<string> */
    private function account(ExternalOrderMapping $mapping): array
    {
        $account = $mapping->account;
        if ($account === null || !$account->enabled) {
            return ['account_off'];
        }

        return array_values(array_filter([
            $account->hasCapability(IntegrationCapability::FulfillmentDispatch) ? null : 'dispatch_capability_off',
            empty(($account->capability_approvals ?? [])[ChannelOperations::SCOPE_BUYER_DATA_TRANSFER]) ? 'buyer_data_approval_missing' : null,
            match ($account->setting('shipment_creation', 'unset')) {
                'app' => null,
                'provider_direct' => 'shipments_are_created_by_the_provider_directly',
                default => 'shipment_creation_not_declared',
            },
        ]));
    }

    /** @return list<string> */
    private function order(ExternalOrderMapping $mapping): array
    {
        $blockers = [];
        if ($mapping->order_id !== null) {
            $blockers[] = 'already_dispatched';
        }
        if ($mapping->shipment_reference !== null || in_array($mapping->shipment_link_state, [ExternalOrderMapping::LINK_LINKED, ExternalOrderMapping::LINK_NEEDS_REVIEW, ExternalOrderMapping::LINK_CONFLICT], true)) {
            $blockers[] = 'shipment_already_known';
        }
        if (!$mapping->orderStatus()->allowsFulfilment()) {
            $blockers[] = 'order_not_open';
        }
        if ($mapping->fulfillmentMode()->isFulfilledByChannel() || $mapping->fulfillmentMode()->value === 'unknown') {
            $blockers[] = 'fulfilment_not_ours';
        }
        $snapshot = (array) $mapping->snapshot;
        $paidHow = (string) ($snapshot['payment_method'] ?? '');
        if (($snapshot['is_cod'] ?? null) === true || preg_match('/cod|cash/i', $paidHow) === 1) {
            $blockers[] = 'cash_on_delivery_not_supported';
        } elseif (!array_key_exists('is_cod', $snapshot) || ($mapping->sales_channel === 'amazon' && $paidHow === '')) {
            // The order is made as already paid: that is only said when the channel said how it was paid.
            $blockers[] = 'payment_method_unknown';
        }
        if (strtoupper((string) (((array) $mapping->snapshot)['destination']['country'] ?? '')) !== 'SA') {
            $blockers[] = 'destination_not_supported';
        }
        if (strtoupper((string) $mapping->currency) !== strtoupper($this->currency->code()) || $this->currency->toStored(1) === null) {
            $blockers[] = 'currency_not_supported';
        }

        return $blockers;
    }

    /** @return list<string> */
    private function lines(ExternalOrderMapping $mapping, string $provider): array
    {
        $active = $mapping->lines->filter(static fn (ExternalOrderLine $line): bool => $line->activeQuantity() > 0);
        if ($active->isEmpty()) {
            return ['no_active_lines'];
        }

        $blockers = [];
        foreach ($active as $line) {
            $product = $line->product_id === null ? null : DB::table('products')->where('id', $line->product_id)->first(['id', 'product_type', 'fulfillment_provider']);
            if ($product === null) {
                $blockers[] = 'line_without_product';
                continue;
            }
            if (($product->product_type ?? 'physical') !== 'physical') {
                $blockers[] = 'product_not_physical';
            }
            if (!empty($product->fulfillment_provider) && $product->fulfillment_provider !== $provider) {
                $blockers[] = 'product_owned_by_other_provider';
            }
            $approved = ProviderProductMapping::where('provider', $provider)->where('product_id', $product->id)->whereIn('variant_key', array_unique([(string) $line->variant_key, '']))->where('status', MappingStatus::Approved->value)->exists();
            if (!$approved) {
                $blockers[] = 'provider_mapping_not_approved';
            }
        }

        return $blockers;
    }
}
