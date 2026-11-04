<?php

namespace Modules\AI\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AICustomerUsagesLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_generate_limit' => ['nullable', 'integer', 'min:0'],
            'customer_image_upload_limit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_generate_limit.integer' => translate('The customer generate limit must be a valid number.'),
            'customer_generate_limit.min' => translate('The customer generate limit must be at least 0.'),
            'customer_image_upload_limit.integer' => translate('The customer image upload limit must be a valid number.'),
            'customer_image_upload_limit.min' => translate('The customer image upload limit must be at least 0.'),
        ];
    }
}
