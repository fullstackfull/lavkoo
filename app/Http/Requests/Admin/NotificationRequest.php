<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationRequest extends FormRequest
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
            'title' => 'required',
            'description' => 'required',
            'image' => getRulesStringForImageValidation(
                rules: ['nullable','image'],
                skipMimes: ['.svg','.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: false
            ),
        ];
    }

    /**
     * @return array[title.required: string]
     */
    public function messages(): array
    {
        return [
            'title.required' => translate('title_is_required'),
            'description.required' => translate('description_is_required'),
            'image.mimes' => translate('only_allowed_').getFileUploadFormats(skip: '.svg,.gif', asMessage: true),
            'image.max' => translate('The_image_may_not_be_greater_than_'). getFileUploadMaxSize() . "MB",
        ];
    }
}
