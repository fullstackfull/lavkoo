<?php

namespace App\Services\Marketplace;

use App\Models\PaymentRoutingRule;
use Illuminate\Support\Facades\Schema;

/**
 * Payment orchestration — resolves which gateways an order may use, and in what order (Phase 3,
 * Stage E).
 *
 * The non-breaking contract: `resolve()` is given the gateways the platform already assembled and
 * returns them **unchanged when no rule matches**. Rules only ever hide a gateway or float a preferred
 * one to the front; they never invent a gateway that was not already available. So checkout behaves
 * exactly as today until rules exist and a caller consults this.
 */
class PaymentRoutingService
{
    /**
     * Filter and order a list of available gateway codes for an order context.
     *
     * @param array<int, string> $availableGateways gateway codes the platform offers
     * @return array<int, string> the resolved list — hidden gateways removed, preferred ones first
     */
    public function resolve(array $availableGateways, float $amount = 0.0, ?string $country = null): array
    {
        $available = array_values(array_unique($availableGateways));
        if ($available === [] || !Schema::hasTable('payment_routing_rules')) {
            return $available;
        }

        $rules = PaymentRoutingRule::where('status', PaymentRoutingRule::STATUS_ACTIVE)->orderBy('priority')->orderBy('id')->get();

        $hidden = [];
        $preferredOrder = [];   // gateway_code => priority

        foreach ($rules as $rule) {
            if (!in_array($rule->gateway_code, $available, true) || !$rule->matches($amount, $country)) {
                continue;
            }
            if ($rule->action === PaymentRoutingRule::ACTION_HIDE) {
                $hidden[$rule->gateway_code] = true;
            } elseif ($rule->action === PaymentRoutingRule::ACTION_PREFER && !isset($preferredOrder[$rule->gateway_code])) {
                $preferredOrder[$rule->gateway_code] = $rule->priority;
            }
        }

        // Remove hidden gateways.
        $remaining = array_values(array_filter($available, fn ($g) => !isset($hidden[$g])));

        // Preferred (that survived hiding), in priority order, then the rest in their original order.
        $preferred = array_keys($preferredOrder);
        asort($preferredOrder);
        $preferred = array_values(array_filter(array_keys($preferredOrder), fn ($g) => in_array($g, $remaining, true)));

        $rest = array_values(array_filter($remaining, fn ($g) => !in_array($g, $preferred, true)));

        return array_merge($preferred, $rest);
    }

    /**
     * Apply routing to a list of gateway *models* rather than bare codes.
     *
     * Each model's gateway code is read from `$codeAttribute` (default `key_name`, the storefront
     * Setting field). The models are returned re-ordered and filtered by the same rules `resolve()`
     * applies to codes — callers get their own models back, so a view iterating the collection (its
     * `->key_name`, its `count()`) is untouched. With no active rule the models come back in their
     * original order, so the caller behaves exactly as before it consulted this.
     *
     * @param  iterable $models  gateway models carrying a code attribute
     * @return \Illuminate\Support\Collection
     */
    public function applyToModels($models, float $amount = 0.0, ?string $country = null, string $codeAttribute = 'key_name')
    {
        $models = collect($models);
        if ($models->isEmpty()) {
            return $models;
        }

        $codes = $models->pluck($codeAttribute)->filter()->values()->all();
        $resolved = $this->resolve($codes, $amount, $country);

        $byCode = $models->keyBy($codeAttribute);
        return collect($resolved)->map(fn ($code) => $byCode->get($code))->filter()->values();
    }

    /** The active rules, for display. */
    public function rules()
    {
        if (!Schema::hasTable('payment_routing_rules')) {
            return collect();
        }

        return PaymentRoutingRule::orderBy('priority')->orderBy('id')->get();
    }
}
