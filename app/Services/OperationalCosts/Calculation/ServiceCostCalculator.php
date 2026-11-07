<?php

namespace App\Services\OperationalCosts\Calculation;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostRule;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Data\ComputedCost;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\Support\FxConverter;

/**
 * Prices services of one company from the contract that was in force on the day of the service.
 *
 * For each requested service it finds the contract, takes that service's rules in priority order, and uses the first rule that applies.
 * The answer is a price, a stated reason for having none (no contract, no matching rule, a fact that is unknown), or "not priced" for a
 * service the contract neither lists nor is expected to cover. It never invents a figure and never returns zero for "I could not tell".
 *
 * A rule is written in the contract's currency, so the value it is applied to (a percentage of the order, a price tier, a minimum order value) is first brought into that
 * currency at the recorded rate. With no rate the value is unknown, and a rule that needs it says so instead of applying 375 SAR as if it were 375 USD.
 */
class ServiceCostCalculator
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly RuleCalculator $rules,
        private readonly FxConverter $fx,
    ) {
    }

    /**
     * @param list<CostComponent> $components
     * @return list<ComputedCost>
     */
    public function price(string $providerCode, array $components, ServiceContext $context, int $sellerId = 0, ?string $currency = null): array
    {
        $contract = $this->contracts->contractFor($providerCode, $context->serviceDate, $sellerId, $currency);
        if ($contract === null) {
            return array_map(static fn (CostComponent $component): ComputedCost => ComputedCost::missing($component, 'no_contract'), $components);
        }

        $rules = $contract->rules()->where('is_active', true)->orderBy('priority')->orderBy('id')->get()->groupBy('service_type');
        $expected = (array) ($contract->settings['expected_components'] ?? []);
        $context = $this->inTheCurrencyOf($contract, $context);

        return array_map(fn (CostComponent $component): ComputedCost => $this->priceOne($contract, $component, $rules->get($component->value)?->all() ?? [], $context, in_array($component->value, $expected, true)), $components);
    }

    private function inTheCurrencyOf(OperationalCostContract $contract, ServiceContext $context): ServiceContext
    {
        if ($context->value === null || $context->currency === $contract->currency) {
            return $context;
        }
        $converted = $this->fx->convert($context->value, $context->currency, $contract->currency);

        return $context->inCurrency($contract->currency, $converted['amount'] ?? null);
    }

    /** @param list<OperationalCostRule> $rules */
    private function priceOne(OperationalCostContract $contract, CostComponent $component, array $rules, ServiceContext $context, bool $expected): ComputedCost
    {
        if ($rules === []) {
            return $expected ? ComputedCost::missing($component, 'not_in_contract', $contract) : ComputedCost::notPriced($component, $contract);
        }

        foreach ($rules as $rule) {
            try {
                $result = $this->rules->calculate($rule, $context);
            } catch (CostInputMissing $missing) {
                // The first rule that could apply needs a fact we do not have. A later rule is not a stand-in for it.
                return ComputedCost::missing($component, $missing->reason === 'value_unknown' && $context->valueWasNotConvertible ? 'fx_unavailable' : $missing->reason, $contract);
            }
            if ($result !== null) {
                return ComputedCost::priced($component, $result, $contract);
            }
        }

        // Every rule of the service was narrowed by a condition this service does not meet (a fee that only applies to cash on delivery).
        return $expected ? ComputedCost::missing($component, 'no_matching_rule', $contract) : ComputedCost::notPriced($component, $contract);
    }
}
