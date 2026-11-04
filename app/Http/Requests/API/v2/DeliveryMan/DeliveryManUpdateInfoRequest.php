<?php

namespace App\Http\Requests\API\v2\DeliveryMan;

use App\Enums\GlobalConstant;
use App\Rules\DisallowedExtension;
use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeliveryManUpdateInfoRequest extends FormRequest
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'f_name'   => 'required',
            'l_name'   => 'required',
            'image' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg', '.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'password' => 'nullable|same:confirm_password|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => 'The first name field is required.',
            'l_name.required' => 'The last name field is required.',
            'image.mimes' => translate('The_image_type_must_be') . getFileUploadFormats(skip: '.svg,.gif,.webp',asMessage: 'true'),
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
