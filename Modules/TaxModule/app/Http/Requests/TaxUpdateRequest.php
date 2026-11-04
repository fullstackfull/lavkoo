<?php
namespace Modules\TaxModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxUpdateRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'name' => [
                'required', 'max:50', Rule::unique('taxes', 'name')->ignore($this['id']),
            ],
            'country_code' => [
                'nullable', 'max:20', Rule::unique('taxes', 'country_code')->ignore($this['id']),
            ],
            'tax_rate' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('name_is_required!'),
            'name.max' => translate('name_must_not_exceed_50_characters!'),
            'name.unique' => translate('this_tax_name_already_exists!'),
            'country_code.max' => translate('country_code_must_not_exceed_20_characters!'),
            'country_code.unique' => translate('this_country_code_already_exists!'),
            'tax_rate.required' => translate('tax_rate_is_required!'),
            'tax_rate.numeric' => translate('tax_rate_must_be_a_valid_number!'),
            'tax_rate.min' => translate('tax_rate_must_be_at_least_0!'),
        ];
    }
}
