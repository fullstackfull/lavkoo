<?php

namespace App\Http\Requests\API\v2\DeliveryMan;

use App\Enums\GlobalConstant;
use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\DisallowedExtension;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

class DeliveryManOrderDeliveryVerificationRequest extends FormRequest
{
    use ResponseHandler;
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required',
            'image.*'    => getRulesStringForImageValidation(
                rules: ['required'],
                skipMimes: ['.svg', '.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => translate('The order ID field is required'),
            'image.*.required'    => translate('The image field is required'),
            'image.*.mimes'       => translate('The_image_type_must_be') . getFileUploadFormats(skip: '.svg,.gif', asMessage: 'true'),
            'image.*.max'         => translate('Maximum_image_size_cannot_exceed_getter_then') . ' ' . getFileUploadMaxSize() . 'MB',
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
