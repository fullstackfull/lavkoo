<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\FulfillmentRoutingRule;
use App\Models\Product;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Collection;

/** A routing rule in words: every condition it sets, as a labelled chip, so that what the rule matches can be read at a glance. A rule that sets none matches every order. */
final class RuleSummary
{
    /**
     * @param Collection<int, string> $accounts account id => name
     * @param Collection<int, string> $locations location id => name
     * @param Collection<int, Product> $products product id => product
     * @return list<array{key: string, value: string}>
     */
    public function chips(FulfillmentRoutingRule $rule, Collection $accounts, Collection $locations, Collection $products): array
    {
        $conditions = (array) ($rule->conditions ?? []);
        $set = [
            translate('channel') => $this->enumLabel(SalesChannel::tryFrom((string) $rule->sales_channel)?->labelKey(), $rule->sales_channel),
            translate('account') => $rule->account_id === null ? null : ($accounts->get($rule->account_id) ?? '#' . $rule->account_id),
            translate('fulfillment_mode') => $this->enumLabel(FulfillmentMode::tryFrom((string) $rule->fulfillment_mode)?->labelKey(), $rule->fulfillment_mode),
            translate('execution_policy') => $this->enumLabel(ExecutionPolicy::tryFrom((string) $rule->execution_policy)?->labelKey(), $rule->execution_policy),
            translate('product') => $rule->product_id === null ? null : trim('#' . $rule->product_id . ' ' . ($products->get($rule->product_id)?->name ?? '')),
            translate('sku') => $rule->sku,
            translate('stock_location') => $rule->inventory_location_id === null ? null : ($locations->get($rule->inventory_location_id) ?? '#' . $rule->inventory_location_id),
            translate('product_provider') => $rule->product_provider === null ? null : (ProviderCode::tryFrom($rule->product_provider)?->label() ?? $rule->product_provider),
            translate('country_code') => $rule->country,
            translate('city') => $rule->city,
            translate('confirmed_stock') => $rule->requires_confirmed_stock ? translate('required') : null,
            translate('min_order_total') => isset($conditions['min_order_total']) ? Money::of((string) $conditions['min_order_total'])->toScale(2) : null,
            translate('max_order_total') => isset($conditions['max_order_total']) ? Money::of((string) $conditions['max_order_total'])->toScale(2) : null,
            translate('cash_on_delivery') => array_key_exists('cod', $conditions) ? translate($conditions['cod'] ? 'yes' : 'no') : null,
        ];

        $chips = [];
        foreach ($set as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $chips[] = ['key' => (string) $key, 'value' => $value];
            }
        }

        return $chips;
    }

    /** A code the enum no longer knows is shown as it is stored. */
    private function enumLabel(?string $labelKey, ?string $stored): ?string
    {
        return $stored === null ? null : ($labelKey === null ? $stored : translate($labelKey));
    }
}
