<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessSettingRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string',
            'country_code' => 'required|string',
            'shop_address' => 'required|string',
            'company_email' => 'required|email',
            'pagination_limit' => 'required|decimal:0,2|min:5|max:100',
            'decimal_point_settings' => 'required|integer|min:0',
            'sales_commission' => 'required|decimal:0,2|min:0|max:100',
            'company_copyright_text' => 'required|string',
            'cookie_text' => [
                Rule::requiredIf($this->has('cookie_status')),
                'nullable',
                'string',
            ],
            'cash_on_delivery' => 'required_without_all:digital_payment,offline_payment|boolean',
            'digital_payment' => 'required_without_all:cash_on_delivery,offline_payment|boolean',
            'offline_payment' => 'required_without_all:cash_on_delivery,digital_payment|boolean',
            'timezone' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => translate('company_name_is_required'),
            'company_name.string' => translate('company_name_must_be_a_string'),
            'country_code.required' => translate('country_code_is_required'),
            'country_code.string' => translate('country_code_must_be_a_string'),
            'shop_address.required' => translate('shop_address_is_required'),
            'shop_address.string' => translate('shop_address_must_be_a_string'),
            'company_email.required' => translate('company_email_is_required'),
            'company_email.email' => translate('company_email_must_be_a_valid_email'),
            'pagination_limit.required' => translate('pagination_limit_is_required'),
            'pagination_limit.min' => translate('pagination_limit_cannot_be_less_than_5'),
            'pagination_limit.max' => translate('pagination_limit_cannot_be_more_than_100'),
            'decimal_point_settings.required' => translate('decimal_point_settings_is_required'),
            'decimal_point_settings.integer' => translate('decimal_point_settings_must_be_an_integer'),
            'decimal_point_settings.min' => translate('decimal_point_settings_cannot_be_negative'),
            'sales_commission.required' => translate('sales_commission_is_required'),
            'sales_commission.integer' => translate('sales_commission_must_be_an_integer'),
            'sales_commission.min' => translate('sales_commission_cannot_be_less_than_zero'),
            'sales_commission.max' => translate('sales_commission_cannot_be_more_than_100'),
            'cash_on_delivery.required_without_all' => 'At least one payment option should be checked.',
            'digital_payment.required_without_all' => 'At least one payment option should be checked.',
            'offline_payment.required_without_all' => 'At least one payment option should be checked.',
        ];
    }

}
