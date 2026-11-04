<?php

namespace App\Http\Requests\API\v3;

use App\Http\Requests\Request;
use App\Rules\DisallowedExtension;
use App\Traits\ResponseHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
class ProductUploadImageRequest extends Request
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxFileSize = getFileUploadMaxSize(unit: 'kb');
        return [
            'image' => getRulesStringForImageValidation(
                rules: ['required'],
                skipMimes: ['.svg'],
                maxSize: $maxFileSize,
                isDisallowed: true
            ),
            'type'  => 'required|in:product,thumbnail,meta',
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => translate('Image is required!'),
            'image.max'      => translate('Image size must not exceed') . ' ' . getFileUploadMaxSize(). ' ' .'MB!',
            'image.mimes' => translate('The image must be a file of type: ') . getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'type.required'  => translate('Type is required!'),
            'type.in'        => translate('Invalid type provided!'),
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
