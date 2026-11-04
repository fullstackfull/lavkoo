<?php

namespace Modules\TaxModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemTaxSetupUpdateRequest extends FormRequest
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
        if ($this['tax_status'] != 'include') {
            return [
                'tax_ids' => 'required_if:tax_type,order_wise',
            ];
        };
        return [];
    }

    public function messages(): array
    {
        return [
            'tax_ids.required_if' => translate('please_select_at_least_one_tax_when_the_tax_type_is_set_to_order_wise.'),
        ];
    }
}
