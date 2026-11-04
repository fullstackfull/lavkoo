<?php

namespace App\Http\Requests\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClearanceSaleSEOSetupRequest extends FormRequest
{
    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'meta_title' => 'string|nullable',
            'meta_description' => 'string|nullable',
            'meta_image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg'],
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'meta_title.required' => translate('the_meta_title_field_is_required'),
            'meta_description.required' => translate('the_meta_description_field_is_required'),
            'meta_image.mimes' => translate('meta_image_must_be_jpg_jpeg_png'),
            'meta_image.max' => translate('meta_image_must_not_exceed_2mb'),
        ];
    }
}
