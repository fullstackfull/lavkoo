<?php

namespace App\Http\Requests\API\v3;

use App\Http\Requests\Request;
use App\Traits\ResponseHandler;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeliveryManAddRequest extends Request
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'f_name' => 'required',
            'l_name' => 'required',
            'phone' => 'required',
            'email' => 'required|email|unique:delivery_men,email',
            'country_code' => 'required',
            'password' => 'required|same:confirm_password|min:8',
            'identity_image.*' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),

        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => translate('First name is required!'),
            'l_name.required' => translate('Last name is required!'),
            'identity_image.*.mimes' => translate('The image must be a file of type.'). getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'identity_image.*.max'   => translate('The image must not exceed '). getFileUploadMaxSize() . ' MB.',

        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'errors' => $this->errorProcessor($validator)
            ], 403)
        );
    }
}
