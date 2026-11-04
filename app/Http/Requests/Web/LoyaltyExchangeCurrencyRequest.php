<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property integer $point
 */
class LoyaltyExchangeCurrencyRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'point' => 'required|numeric|min:1'
        ];
    }

    public function messages(): array
    {
        return [
            'point.required' => translate('the_point_field_is_required'),
            'point.numeric' => translate('the_point_field_must_be_a_number'),
            'point.min' => translate('the_point_field_must_be_at_least_one'),
        ];
    }

}
