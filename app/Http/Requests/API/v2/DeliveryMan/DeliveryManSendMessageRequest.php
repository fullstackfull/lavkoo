<?php

namespace App\Http\Requests\API\v2\DeliveryMan;

use App\Enums\GlobalConstant;
use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\DisallowedExtension;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

class DeliveryManSendMessageRequest extends FormRequest
{
    use ResponseHandler;
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required',
            'message' => 'required_without_all:file,media',
            'media.*' => [
                'file',
                new DisallowedExtension(),
                'max:' . getFileUploadMaxSize(unit: 'kb'),
                'mimes:' . str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),
            ],
            'file.*' => [
                'file',
                new DisallowedExtension(),
                'max:' . getFileUploadMaxSize(unit: 'kb'),
                'mimes:' . str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'required_without_all' => translate('type_something') . '!',
            'media.*.mimes' => translate('the_media_format_is_not_supported') . ' ' .
                translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),

            'media.*.max' => translate('media_maximum_size') . ' ' .
                (getFileUploadMaxSize() / 1024) . ' MB',

            'file.*.mimes' => translate('the_file_format_is_not_supported') . ' ' .
                translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),

            'file.*.max' => translate('file_maximum_size_') .
                (getFileUploadMaxSize() / 1024) . ' MB',
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
