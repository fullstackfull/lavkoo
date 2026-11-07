<?php

namespace App\Http\Requests\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Http\Controllers\Admin\OperationalCosts\DecimalRules;
use App\Services\OperationalCosts\ContractService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The header of a contract: who it is with, which currency its prices are in, when it applies, and which services it is expected to price. No price is asked for here. */
class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('provider_code'))) {
            $this->merge(['provider_code' => strtolower(trim($this->input('provider_code')))]);
        }
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'party_type' => ['required', Rule::in(ContractService::PARTY_TYPES)],
            'provider_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.-]+$/'],
            'party_name' => ['required', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['required', 'regex:/^[A-Z]{3,8}$/'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', ...(filled($this->input('effective_from')) ? ['after_or_equal:effective_from'] : [])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'seller_id' => ['nullable', 'integer', 'min:0'],
            'expected_components' => ['nullable', 'array'],
            'expected_components.*' => ['string', Rule::in(array_map(static fn (CostComponent $component): string => $component->value, CostComponent::contractPriced()))],
            'tolerance_percent' => DecimalRules::percent(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'party_type' => translate('kind_of_company'),
            'provider_code' => translate('company_code'),
            'party_name' => translate('company_name'),
            'reference' => translate('contract_reference'),
            'currency' => translate('currency'),
            'effective_from' => translate('valid_from'),
            'effective_to' => translate('valid_to'),
            'notes' => translate('notes'),
            'seller_id' => translate('seller'),
            'expected_components' => translate('expected_services'),
            'expected_components.*' => translate('expected_services'),
            'tolerance_percent' => translate('reconciliation_tolerance_percent'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'regex' => translate('enter_a_valid_value_for_this_field'),
        ];
    }

    /**
     * What ContractService::createDraft() and updateDraft() take.
     *
     * @return array<string, mixed>
     */
    public function contractData(): array
    {
        $validated = $this->validated();
        $settings = [];
        if (!empty($validated['expected_components'])) {
            $settings['expected_components'] = array_values($validated['expected_components']);
        }
        if (isset($validated['tolerance_percent'])) {
            $settings['tolerance_percent'] = $validated['tolerance_percent'];
        }

        return [
            'party_type' => $validated['party_type'],
            'provider_code' => $validated['provider_code'],
            'party_name' => $validated['party_name'],
            'reference' => $validated['reference'] ?? null,
            'currency' => $validated['currency'],
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'settings' => $settings,
        ];
    }

    public function sellerId(): int
    {
        return (int) ($this->validated()['seller_id'] ?? 0);
    }
}
