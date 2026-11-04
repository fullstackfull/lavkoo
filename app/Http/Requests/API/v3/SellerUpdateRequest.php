<?php

namespace App\Http\Requests\API\v3;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class SellerUpdateRequest extends FormRequest
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg', '.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true),
        ];
    }

    public function messages(): array
    {
       return [
           'image.required' => translate('Profile image is required.'),
           'image.mimes' => translate('The profile image must be a file of type: ') . getFileUploadFormats(skip: ['.svg', '.gif'], asMessage: true),
           'image.max' => translate('The profile image may not be greater than ') . getFileUploadMaxSize() . ' MB.',

       ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => $this->errorProcessor($validator)
            ], 403)
        );
    }
}
