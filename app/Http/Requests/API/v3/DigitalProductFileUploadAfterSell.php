<?php

namespace App\Http\Requests\API\v3;


use App\Enums\GlobalConstant;
use App\Rules\DisallowedExtension;
use App\Traits\ResponseHandler;
use App\Utils\Helpers;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DigitalProductFileUploadAfterSell extends FormRequest
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
        return [
            'order_id' => 'required',
            'digital_file_ready' => [
                'required',
                'file',
                new DisallowedExtension(),
                'max:' . getFileUploadMaxSize(unit: 'kb'),
                'mimes:jpg,jpeg,png,gif,zip,pdf',

            ],
        ];

    }

    public function messages(): array
    {
        return [
            'order_id.required' => translate('Order ID is required'),
            'digital_file_ready.required' => translate('The digital file is required'),
            'digital_file_ready.mimes' => translate('The digital file format is not supported. Allowed formats: jpg, jpeg, png, gif, zip, pdf.'),
        ];
    }


    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
