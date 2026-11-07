<?php

namespace App\Http\Requests\Admin\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\DecimalRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Goods that came into stock at a cost. The unit cost is typed in as a decimal string and stays one until the service turns it into a Money. */
class RecordPurchaseCostRequest extends FormRequest
{
    /** What a stock cost layer can come from when it is recorded by hand. */
    public const SOURCE_TYPES = ['purchase_invoice', 'manual', 'opening_balance', 'transfer'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')],
            'product_id' => ['required', 'integer', 'min:1', Rule::exists('products', 'id')],
            'variant_key' => ['nullable', 'string', 'max:191'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'unit_cost' => DecimalRules::positiveAmount(),
            'landed_unit_cost' => DecimalRules::amount(),
            'currency' => ['required', 'regex:/^[A-Z]{3,8}$/'],
            'received_at' => ['required', 'date_format:Y-m-d'],
            'source_type' => ['required', Rule::in(self::SOURCE_TYPES)],
            'reference' => ['required', 'string', 'max:120'],
            'supplier_name' => ['nullable', 'string', 'max:160'],
            'confirm_separate_purchase' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'location_id' => translate('location'),
            'product_id' => translate('product'),
            'variant_key' => translate('variant_key'),
            'quantity' => translate('quantity'),
            'unit_cost' => translate('unit_cost'),
            'landed_unit_cost' => translate('landed_cost_per_unit'),
            'currency' => translate('currency'),
            'received_at' => translate('received_on'),
            'source_type' => translate('source'),
            'reference' => translate('reference'),
            'supplier_name' => translate('supplier'),
            'confirm_separate_purchase' => translate('this_is_a_separate_purchase'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'regex' => translate('enter_a_plain_number_such_as_12_50'),
        ];
    }
}
