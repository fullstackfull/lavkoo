<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VendorBusinessProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:51',
            'sub_title' => 'required|string|max:161',
        ];

        for ($i = 1; $i <= 3; $i++) {
            $rules["section_{$i}_title"] = 'required|string|max:51';
            $rules["section_{$i}_description"] = 'required|string|max:161';
            $rules["section_{$i}_image"] = 'nullable|image|'.getFileUploadFormats(skip: '.svg,.gif', asRule: 'true').'|max:'. getFileUploadMaxSize(unit: 'kb');
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'title.required' => translate('title_is_required'),
            'title.string' => translate('title_must_be_string'),
            'title.max' => translate('title_must_not_exceed_51_characters'),

            'sub_title.required' => translate('sub_title_is_required'),
            'sub_title.string' => translate('sub_title_must_be_string'),
            'sub_title.max' => translate('sub_title_must_not_exceed_161_characters'),
        ];

        for ($i = 1; $i <= 3; $i++) {
            $messages["section_{$i}_title.required"] = translate("section_{$i}_title_is_required");
            $messages["section_{$i}_title.string"] = translate("section_{$i}_title_must_be_string");
            $messages["section_{$i}_title.max"] = translate("section_{$i}_title_must_not_exceed_51_characters");

            $messages["section_{$i}_description.required"] = translate("section_{$i}_description_is_required");
            $messages["section_{$i}_description.string"] = translate("section_{$i}_description_must_be_string");
            $messages["section_{$i}_description.max"] = translate("section_{$i}_description_must_not_exceed_161_characters");

            $messages["section_{$i}_image.image"] = translate("section_{$i}_image_must_be_an_image");
            $messages["section_{$i}_image.mimes"] = translate("section_{$i}_image_invalid_format");
            $messages["section_{$i}_image.max"] = translate("section_{$i}_image_must_not_exceed_2mb");
        }

        return $messages;
    }
}
