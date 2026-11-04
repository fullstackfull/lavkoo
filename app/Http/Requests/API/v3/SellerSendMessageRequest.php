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

class SellerSendMessageRequest extends FormRequest
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
        $maximumUploadSize = getFileUploadMaxSize(unit: 'kb');

        return [
            'id' => 'required',
            'message' => 'required_without_all:file,media',
            'media.*' => [
                'file',
                new DisallowedExtension(),
                'max:' .$maximumUploadSize,
                'mimes:' . str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),
            ],
            'file.*' => [
                'file',
                new DisallowedExtension(),
                'max:' . $maximumUploadSize,
                'mimes:' . str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),
            ],
        ];
    }

    public function messages(): array
    {
        $maximumUploadSize = getFileUploadMaxSize();

        return [
            'required_without_all' => translate('type_something') . '!',

            'media.mimes' => translate('the_media_format_is_not_supported') . ' ' . translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),

            'media.max' => translate('media_maximum_size') . ' ' . $maximumUploadSize . ' MB',

            'file.mimes' => translate('the_file_format_is_not_supported') . ' ' . translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),

            'file.max' => translate('file_maximum_size_') . $maximumUploadSize . ' MB',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
