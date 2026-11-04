<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RobotsMetaContentAddRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }



    public function rules(): array
    {

        return [
            'meta_title' => 'required|string|max:60',
            'meta_description' => 'required|string|max:160',
            'meta_image' => 'nullable|image|' . getFileUploadFormats(skip: '.svg,.webp',asRule: 'true') . '|max:' . getFileUploadMaxSize(unit: 'kb'),
        ];
    }
    public function messages(): array{
        return [
            'meta_title.required' => translate('meta_title_required'),
            'meta_title.max' => translate('meta_title_max_60_characters'),
            'meta_description.required' => translate('meta_description_required'),
            'meta_description.max' => translate('meta_description_max_160_characters'),
            'meta_image.mimes' => translate('meta_image_must_be_of_type_').translate(getFileUploadFormats(skip: '.svg,.webp', asMessage: 'true')),
            'meta_image.max' => translate('meta_image_may_not_be_greater_than_'). getFileUploadMaxSize().'MB',
        ];
    }
}
