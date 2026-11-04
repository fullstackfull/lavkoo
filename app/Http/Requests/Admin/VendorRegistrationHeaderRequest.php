<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VendorRegistrationHeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return  [
            'title' => 'required|string|max:51',
            'sub_title' => 'required|string|max:161',
            'image' => 'nullable|image|'.getFileUploadFormats(skip: '.svg,.gif', asRule: 'true').'|max:'.getFileUploadMaxSize(unit: 'kb'),
        ];
    }
    public function messages(): array{
        return [
            'title.required' => translate('title_is_required'),
            'title.max' => translate('title_may_not_be_greater_than_51_characters'),
            'sub_title.required' => translate('sub_title_is_required'),
            'sub_title.max' => translate('sub_title_may_not_be_greater_than_161_characters'),
            'image.image' => translate('image_type_must_be').' jpg, jpeg, png',
            'image.max' => translate('image_max_size_is_2_MB'),
        ];
    }
}
