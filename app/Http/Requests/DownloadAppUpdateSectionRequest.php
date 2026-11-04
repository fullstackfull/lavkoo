<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadAppUpdateSectionRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];
        if($this->section_type == 'title_section'){
            $rules+= [
                'title' => 'required|string|max:50',
                'sub_title' => 'required|string|max:160',
                'image' => getRulesStringForImageValidation(
                    rules: ['sometimes', 'image'],
                    skipMimes: ['.svg'],
                    maxSize: getFileUploadMaxSize(unit: 'kb'),
                    isDisallowed: true
                ),
            ];
        }elseif($this->section_type == 'link_section') {
            $rules += [
                'download_google_app' => 'required|string',
                'download_apple_app' => 'required|string',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'title.required' => translate('download_app_title_is_required'),
            'title.string' => translate('download_app_title_must_be_a_string'),
            'title.max' => translate('download_app_title_max_character_is_50'),

            'sub_title.required' => translate('download_app_subtitle_is_required'),
            'sub_title.string' => translate('download_app_subtitle_must_be_a_string'),
            'sub_title.max' => translate('download_app_subtitle_max_character_is_160'),

            'image.mimes' => translate('image_type_must_be'). ' ' . getFileUploadFormats(skip: '.svg'),
            'image.max' => translate('image_max_size_is'). ' ' . getFileUploadMaxSize() . ' MB',

            'download_google_app.required' => translate('google_play_link_is_required'),
            'download_google_app.string' => translate('google_play_link_must_be_a_valid_string'),
            'download_apple_app.required' => translate('apple_store_link_is_required'),
            'download_apple_app.string' => translate('apple_store_link_must_be_a_valid_string'),
        ];
    }

}
