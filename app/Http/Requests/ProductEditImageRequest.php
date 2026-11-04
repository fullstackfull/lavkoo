<?php

namespace App\Http\Requests;

use App\Traits\CalculatorTrait;
use App\Traits\ResponseHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class ProductEditImageRequest extends Request
{
    use CalculatorTrait, ResponseHandler;
    use VatTaxManagement;

    protected $stopOnFirstFailure = false;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
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
            'image' => getRulesStringForImageValidation(
                rules: ['nullable', 'image'],
                skipMimes: ['.webp'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            )
        ];
    }

    public function messages(): array
    {
        return [
            'image' . '.' . 'required' => translate('product_thumbnail_is_required!'),
            'image.mimes' => translate('The image format is not supported. Allowed formats:'). getFileUploadFormats( skip: '.webp', asMessage: 'true'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $disallowedExtensions = getDisallowedExtensionsListArray();


            }
        ];
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
