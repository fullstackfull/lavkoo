<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddonPurchaseCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:255',
            'username' => 'required|string',
            'purchase_key' => [
                'required',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('The name field is required.'),
            'name.string' => translate('The name must be a valid string.'),
            'name.max' => translate('The name may not be greater than 150 characters.'),

            'email.required' => translate('The email address is required.'),
            'email.email' => translate('Please provide a valid email address.'),
            'email.max' => translate('The email may not be greater than 255 characters.'),

            'username.required' => translate('The username field is required.'),
            'username.string' => translate('The username must be a valid string.'),

            'purchase_key.required' => translate('The purchase key is required.'),
            'purchase_key.string' => translate('The purchase key must be a valid string.'),
        ];
    }
}
