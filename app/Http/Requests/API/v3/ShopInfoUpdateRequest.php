<?php

namespace App\Http\Requests\API\v3;


use App\Enums\GlobalConstant;
use App\Rules\DisallowedExtension;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShopInfoUpdateRequest extends FormRequest
{
    use ResponseHandler;

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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $maxFileSize = getFileUploadMaxSize(unit: 'kb');

        return [
            'logo' =>  getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg', '.gif'],
                maxSize: $maxFileSize,
                isDisallowed: true
            ),
            'banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: $maxFileSize,
                isDisallowed: true
            ),
            'bottom_banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: $maxFileSize,
                isDisallowed: true
            ),
            'offer_banner' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: $maxFileSize,
                isDisallowed: true
            ),
            'tin_certificate' => 'nullable|mimes:pdf,doc,docx,jpg|max:' . $maxFileSize,
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimes' => translate('The logo must be a file of type: ') . getFileUploadFormats(skip: ['.svg', '.gif'], asMessage: true),
            'banner.mimes' => translate('The banner must be a file of type: ') . getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'bottom_banner.mimes' => translate('The bottom banner must be a file of type: ') . getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'offer_banner.mimes' => translate('The offer banner must be a file of type: ') . getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'tin_certificate.mimes' => translate('The tin certificate must be a file of type: ') . getFileUploadFormats(skip: ['.png', '.gif', '.svg'], asMessage: true) . getFileUploadFormats(type: 'file', skip: ['.txt'], asMessage: 'true'),
            'tin_certificate.max' => translate('The tin certificate may not be greater than ') . getFileUploadMaxSize() . ' MB.',
        ];
    }


    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
