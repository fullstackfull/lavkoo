<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BusinessPageAddRequest extends FormRequest
{
    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|unique:business_pages,title',
            'description' => 'required|string',
            'slug' => 'string|unique:business_pages,slug',
            'banner' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg', '.webp'],
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => translate('title_is_required'),
            'description.required' => translate('description_is_required'),
            'slug.unique' => translate('slug_must_be_unique'),
            'banner.mimes' => translate('banner_image_type_must_be').' jpg, jpeg, png, gif',
            'banner.max' => translate('banner_image_max_size_is_2_MB'),
        ];
    }

}
