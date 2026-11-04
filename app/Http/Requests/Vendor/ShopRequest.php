<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class ShopRequest extends FormRequest
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
    public function rules():array
    {
        return [
            'name' => 'required:string|max:255',
            'company_phone' => 'required',
            'country_code' => 'required',
            'address' => 'required|string',
            'banner' =>getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'image' => getRulesStringForImageValidation(
              rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'bottomBanner' =>getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'offerBanner' => getRulesStringForImageValidation(
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
            'banner.mimes' => translate('banner_image_type_'). getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'banner.max' => translate('banner_maximum_size_') .  getFileUploadMaxSize()."MB",
            'image.mimes' => translate('image_type_jpg,_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'image.max' => translate('image_maximum_size_') .  getFileUploadMaxSize()."MB",
            'bottomBanner.mimes' => translate('bottom_banner_type_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'bottomBanner.max' => translate('bottom_banner_maximum_size_') .  getFileUploadMaxSize()."MB",
            'offerBanner.mimes' => translate('offer_banner_type_').getFileUploadFormats(skip: '.svg', asMessage: 'true'),
            'offerBanner.max' => translate('offer_banner_maximum_size_') . getFileUploadMaxSize()."MB",
        ];
    }
}
