<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BusinessPageUpdateRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (in_array(Str::slug($this['title']), ['terms-and-conditions', 'about-us', 'privacy-policy'])) {
            $this->merge(['status' => 1]);
        }

        return [
            'title' => [
                'required', 'string', Rule::unique('business_pages', 'title')->ignore($this['id']),
            ],
            'description' => 'string',
            'slug' => [
                'string', Rule::unique('business_pages', 'slug')->ignore($this['id']),
            ],
            'banner' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => translate('title_is_required'),
            'slug.unique' => translate('slug_must_be_unique'),
            'banner.mimes' => translate('banner_image_type_must_be') . getFileUploadFormats(skip: '.svg'),
            'banner.max' => translate('banner_image_max_size_is_'). getFileUploadMaxSize(). 'MB',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $description = $this->input('description');
                if (is_array($description)) {
                    $first = reset($description);
                    $cleanedDescription = is_string($first) ? trim(strip_tags($first)) : null;
                } else {
                    $cleanedDescription = is_string($description) ? trim(strip_tags($description)) : null;
                }

                if (empty($cleanedDescription)) {
                    $validator->errors()->add(
                        'description', translate('Description_is_required') . '!'
                    );
                }
            }
        ];
    }

}
