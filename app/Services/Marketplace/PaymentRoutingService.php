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

        $rules = $this->activeRules();

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

    /**
     * The same list, judged where there is NO order to judge against -- the global config response.
     *
     * /api/v1/config is asked before there is a cart, an amount or a delivery address, so most rules
     * cannot be decided there: a rule bound to an amount band or to a country might or might not apply
     * to the order the customer goes on to place, and removing a method on a maybe would hide a method
     * the customer is entitled to. ONLY rules that name neither an amount band nor a country are
     * applied, because those apply wherever they are read.
     *
     * Deliberately NOT resolve($available, 0.0, null): a rule with only a max_amount matches an amount
     * of zero, so passing zero would let an amount-bound rule hide a method from every mobile client.
     *
     * @param array<int, string> $availableGateways
     * @return array<int, string>
     */
    public function resolveWithoutAnOrder(array $availableGateways): array
    {
        $available = array_values(array_unique($availableGateways));
        if ($available === [] || !Schema::hasTable('payment_routing_rules')) {
            return $available;
        }

        $hidden = [];
        foreach ($this->activeRules() as $rule) {
            if ($rule->action === PaymentRoutingRule::ACTION_HIDE && $rule->appliesEverywhere() && in_array($rule->gateway_code, $available, true)) {
                $hidden[$rule->gateway_code] = true;
            }
        }

        return array_values(array_filter($available, static fn ($gateway) => !isset($hidden[$gateway])));
    }

    /**
     * Apply the same judgement to gateway MODELS where there is no order, keeping the caller's own models.
     *
     * @return \Illuminate\Support\Collection
     */
    public function applyToModelsWithoutAnOrder($models, string $codeAttribute = 'key_name')
    {
        $models = collect($models);
        if ($models->isEmpty()) {
            return $models;
        }

        $kept = $this->resolveWithoutAnOrder($models->pluck($codeAttribute)->filter()->values()->all());
        $byCode = $models->keyBy($codeAttribute);

        return collect($kept)->map(fn ($code) => $byCode->get($code))->filter()->values();
    }

    /**
     * The same list, judged for a STOREFRONT rather than for an order (J-09.4).
     *
     * This sits between `resolve()` and `resolveWithoutAnOrder()`, and the difference is what is known. The config answer of a market
     * knows its COUNTRY -- the app says which storefront it is asking for -- so a rule bound to a country can be judged there, which is
     * exactly what `resolveWithoutAnOrder()` had to skip. What is still unknown is the AMOUNT: there is no cart yet, so a rule bound to an
     * amount band might or might not apply to the order the customer goes on to place, and hiding a method on that maybe would hide a
     * method the customer is entitled to. Such a rule is left alone here too.
     *
     * A rule naming no country covers every destination (`countryMatches`), so the everywhere-rules `resolveWithoutAnOrder()` applies are
     * applied here as well: this list is never wider than that one.
     *
     * @param array<int, string> $availableGateways
     * @return array<int, string>
     */
    public function resolveForStorefront(array $availableGateways, string $country): array
    {
        $available = array_values(array_unique($availableGateways));
        if ($available === [] || !Schema::hasTable('payment_routing_rules')) {
            return $available;
        }

        $hidden = [];
        foreach ($this->activeRules() as $rule) {
            $boundToAnAmount = $rule->min_amount !== null || $rule->max_amount !== null;

            if ($rule->action === PaymentRoutingRule::ACTION_HIDE && !$boundToAnAmount && $rule->countryMatches($country) && in_array($rule->gateway_code, $available, true)) {
                $hidden[$rule->gateway_code] = true;
            }
        }

        return array_values(array_filter($available, static fn ($gateway) => !isset($hidden[$gateway])));
    }

    /**
     * Apply the storefront judgement to gateway MODELS, keeping the caller's own models.
     *
     * @return \Illuminate\Support\Collection
     */
    public function applyToModelsForStorefront($models, string $country, string $codeAttribute = 'key_name')
    {
        $models = collect($models);
        if ($models->isEmpty()) {
            return $models;
        }

        $kept = $this->resolveForStorefront($models->pluck($codeAttribute)->filter()->values()->all(), $country);
        $byCode = $models->keyBy($codeAttribute);

        return collect($kept)->map(fn ($code) => $byCode->get($code))->filter()->values();
    }

    /**
     * Is this gateway hidden for an order of this amount going to this destination?
     *
     * The payment START asks this. A customer who was offered a method before a rule was written, or
     * who posts the method straight to the payment route, would otherwise be handed to a gateway the
     * rules say must not take this order -- and the money would move before anything noticed. Only a
     * gateway the platform offers at all is judged: whether an unknown gateway may start a payment is
     * a different question, answered elsewhere, and this must not quietly take it over.
     */
    public function hides(string $gateway, array $availableGateways, float $amount = 0.0, ?string $country = null): bool
    {
        if (!in_array($gateway, $availableGateways, true)) {
            return false;
        }

        return !in_array($gateway, $this->resolve($availableGateways, $amount, $country), true);
    }

    /** The active rules in the order they are judged. */
    private function activeRules()
    {
        return PaymentRoutingRule::where('status', PaymentRoutingRule::STATUS_ACTIVE)->orderBy('priority')->orderBy('id')->get();
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
