<?php

namespace App\Http\Requests\Admin\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\Forms\ContractRuleForm;
use Illuminate\Foundation\Http\FormRequest;

/** One price rule of a draft contract. The shape of every figure is checked here; whether the figures make a price list (steps in order, tiers that do not overlap) is RuleValidator's to say. */
class ContractRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ContractRuleForm::rules($this->all());
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'service_type' => translate('service'),
            'calc_method' => translate('calculation_method'),
            'label' => translate('label'),
            'priority' => translate('priority'),
            'valid_from' => translate('valid_from'),
            'valid_to' => translate('valid_to'),
            'amount' => translate('rule_amount'),
            'percent' => translate('rule_percentage'),
            'min' => translate('rule_minimum_charge'),
            'max' => translate('rule_maximum_charge'),
            'extra_per_kg' => translate('rule_extra_charge_per_step'),
            'extra_step_kg' => translate('rule_extra_step_size'),
            'extra_per_m3' => translate('rule_extra_charge_per_step'),
            'extra_step_m3' => translate('rule_extra_step_size'),
            'conditions.min_weight_kg' => translate('condition_min_weight_kg'),
            'conditions.max_weight_kg' => translate('condition_max_weight_kg'),
            'conditions.min_value' => translate('condition_min_value'),
            'conditions.max_value' => translate('condition_max_value'),
            'weight_steps.*.limit' => translate('rule_up_to_kg'),
            'weight_steps.*.amount' => translate('rule_charge'),
            'volume_steps.*.limit' => translate('rule_up_to_m3'),
            'volume_steps.*.amount' => translate('rule_charge'),
            'zones.*.amount' => translate('rule_charge'),
            'quantity_tiers.*.from' => translate('rule_from_units'),
            'quantity_tiers.*.to' => translate('rule_to_units'),
            'quantity_tiers.*.amount' => translate('rule_charge'),
            'value_tiers.*.from' => translate('rule_from_value'),
            'value_tiers.*.to' => translate('rule_to_value'),
            'value_tiers.*.amount' => translate('rule_charge'),
            'value_tiers.*.percent' => translate('rule_percentage'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'regex' => translate('enter_a_plain_number_such_as_12_50'),
        ];
    }

    /**
     * What ContractService::addRule() and updateRule() take.
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        return ContractRuleForm::serviceData($this->validated());
    }
}
