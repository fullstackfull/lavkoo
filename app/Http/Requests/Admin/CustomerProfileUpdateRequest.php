<?php

namespace App\Http\Requests\Admin;

use App\Traits\CalculatorTrait;
use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomerProfileUpdateRequest extends FormRequest
{
    use CalculatorTrait, ResponseHandler;

    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => [
                'required',
                'max:50',
                Rule::unique('users', 'email')->ignore($this->id, 'id'),
            ],
            'image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg'],
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => translate('first_name_is_required'),
            'l_name.required' => translate('last_name_is_required'),
            'phone.required' => translate('phone_number_is_required'),
            'phone.unique' => translate('phone_number_already_has_been_taken'),
            'phone.max' => translate('The_phone_number_may_not_be_greater_than_20_characters'),
            'image.mimes' => translate('The_image_type_must_be') . '.jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff,.webp',
        ];
    }

}
