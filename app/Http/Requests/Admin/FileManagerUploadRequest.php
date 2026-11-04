<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;


/**
 * @property-read string $images
 * @property-read string $file
 * @property-read string $path
 */
class FileManagerUploadRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => 'required_without:file',
            'images.*' => [
                'mimes:jpg,jpeg,png,gif,webp',
                'max:' . getFileUploadMaxSize(unit: 'kb'),
            ],
            'file' => 'required_without:images',
            'path' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'images.required_without' => translate('either_images_or_file_required'),
            'images.*.mimes' => translate('upload_file_must_be_jpeg_jpg_png_gif_webp_format'),
            'images.*.max' => translate('each_uploaded_image_may_not_be_greater_than_2_MB'),
            'file.required_without' => translate('either_images_or_file_required'),
            'path.required' => translate('the_path_is_required'),
        ];
    }

}
