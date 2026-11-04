<?php

namespace Modules\AI\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AIVendorUsagesLimitRequest extends FormRequest
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
     */
    public function rules(): array
    {

        return [
            'image_upload_limit' => ['nullable', 'integer', 'min:0'],
            'generate_limit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'image_upload_limit.integer' => translate('The image upload limit must be a valid number.'),
            'image_upload_limit.min' => translate('The image upload limit must be at least 0.'),
            'generate_limit.integer' => translate('The generate limit must be a valid number.'),
            'generate_limit.min' => translate('The generate limit must be at least 0.'),
        ];
    }
}
