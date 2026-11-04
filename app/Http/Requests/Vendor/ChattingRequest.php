<?php

namespace App\Http\Requests\Vendor;

use App\Enums\GlobalConstant;
use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;

class ChattingRequest extends FormRequest
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
     * Validation rules
     */
    public function rules(): array
    {
        $maximumUploadSize = checkServerUploadMaxFileSizeInMB();

        return [
            'message' => 'required_without_all:file,media',

            'media.*' => 'bail|max:' . $maximumUploadSize . '|mimes:' .
                str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),

            'file.*' => 'bail|file|max:2048|mimes:' .
                str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),
        ];
    }

    /**
     * Custom validation messages
     */
    public function messages(): array
    {
        $maximumUploadSize = checkServerUploadMaxFileSizeInMB();

        return [
            'message.required_without_all' => translate('type_something') . '!',

            'media.*.mimes' => translate('the_media_format_is_not_supported') . ' ' .
                translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::MEDIA_EXTENSION)),

            'media.*.max' => translate('media_maximum_size') . ' ' . ($maximumUploadSize / 1024) . ' MB',

            'file.*.mimes' => translate('the_file_format_is_not_supported') . ' ' .
                translate('supported_format_are') . ' ' .
                str_replace('.', '', implode(',', GlobalConstant::DOCUMENT_EXTENSION)),

            'file.*.max' => translate('file_maximum_size_') . ' 2 MB',
        ];
    }

    /**
     * Custom attribute names
     */
    public function attributes(): array
    {
        return [
            'file.*' => 'file',
            'media.*' => 'media',
        ];
    }
}
