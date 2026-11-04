<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InHouseShopRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize():bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'contact' => 'required',
            'country_code' => 'required',
            'address' => 'required|string',

            'shop_banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),

            'bottom_banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),

            'offer_banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
        ];
    }
    public function messages():array
    {
        return [
            'shop_banner.mimes' => translate('banner_image_type_'). getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'shop_banner.max' => translate('banner_maximum_size_') .  getFileUploadMaxSize()."MB",
            'image.mimes' => translate('image_type_jpg,_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'image.max' => translate('image_maximum_size_') .  getFileUploadMaxSize()."MB",
            'bottom_banner.mimes' => translate('bottom_banner_type_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'bottom_banner.max' => translate('bottom_banner_maximum_size_') .  getFileUploadMaxSize()."MB",
            'offer_banner.mimes' => translate('offer_banner_type_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'offer_banner.max' => translate('offer_banner_maximum_size_') . getFileUploadMaxSize()."MB",
        ];
    }
}
