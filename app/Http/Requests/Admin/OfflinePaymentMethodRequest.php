<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OfflinePaymentMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'method_name' => 'required|string|max:255',
            'input_name' => 'required|array|min:1',
            'input_name.*' => 'required|string|max:255',
            'input_data' => 'required|array|min:1',
            'input_data.*' => 'required|string|max:255',
            'customer_input' => 'required|array|min:1',
            'customer_input.*' => 'required|string|max:255',
            'customer_placeholder' => 'required|array|min:1',
            'customer_placeholder.*' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'method_name.required' => translate('method_name_is_required'),
            'input_name.*.required' => translate('input_field_name_is_required'),
            'input_data.*.required' => translate('input_data_is_required'),
            'customer_input.*.required' => translate('customer_input_field_is_required'),
            'customer_placeholder.*.required' => translate('customer_placeholder_is_required'),
        ];
    }
}
