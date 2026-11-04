<?php

namespace Modules\AI\app\Http\Requests\ApiRequests;

use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GenerateTitleFromImageRequest extends FormRequest
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'image' => getRulesStringForImageValidation(
                rules: ['required', 'image'],
                skipMimes: ['.svg','.webp'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => translate('Image is required for analysis.'),
            'image.image'    => translate('The uploaded file must be an image.'),
            'image.mimes'    => translate('The image must be a file of type:') . getFileUploadFormats(skip: ['.svg', '.webp'], asMessage: true),
            'image.max'      => translate('Image size must not exceed '). getFileUploadMaxSize() . ' MB.',
        ];
    }


    /**
     * Determine if the user is authorized to make this request.
     */


    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)], 403));
    }
}
