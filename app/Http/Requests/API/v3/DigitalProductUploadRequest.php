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

class DigitalProductUploadRequest extends FormRequest
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
            'digital_file_ready.required' => trans('The digital file is required'),
            'digital_file_ready.mimes' => 'The digital file format is not supported. Allowed formats: jpg, jpeg, png, gif, zip, pdf.',
        ];
    }


    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
