<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $id
 * @property string $url
 * @property string $image
 * @property int $status
 */
class BannerAddRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => 'required_if:resource_type,custom|nullable|url',
            'image' => getRulesStringForImageValidation(
                rules: ['required', 'image'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            // Optional, but held to the same rules as the web image — it is stored and
            // served to the apps exactly the same way.
            'mobile_image' => getRulesStringForImageValidation(
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
            'url.required_if' => translate('the_url_field_is_required'),
            'image.required' => translate('the_image_is_required'),
            'image.max' => translate('the_image_size_max_').getFileUploadMaxSize().' '.translate('MB'),
            'image.mimes' => translate('only_'). getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'mobile_image.max' => translate('the_image_size_max_').getFileUploadMaxSize().' '.translate('MB'),
            'mobile_image.mimes' => translate('only_'). getFileUploadFormats(skip: '.svg', asMessage: 'true'),
        ];
    }

}
