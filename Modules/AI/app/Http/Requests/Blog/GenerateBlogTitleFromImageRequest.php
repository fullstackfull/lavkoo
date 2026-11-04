<?php

namespace Modules\AI\app\Http\Requests\Blog;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GenerateBlogTitleFromImageRequest extends FormRequest
{
    use   ResponseHandler;
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'description' => 'nullable|string',
            'image' => 'required|image|'. getFileUploadFormats(skip: '.svg', asRule: 'true'). '|max:'.getFileUploadMaxSize(unit: 'kb'),
        ];
    }

    public function messages(): array{
        return [
            'description.string' => 'Description must be a string.',
            'image.image' => translate('The uploaded file must be an image.'),
            'image.mimes' => translate('Only'.getFileUploadFormats(skip: '.svg', asMessage: 'true'). 'are_allowed'),
            'image.max' => translate('Image size must not exceed 1MB.'),
        ];
    }
    /**
     * Determine if the user is authorized to make this request.
     */

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
    public function authorize(): bool
    {
        return true;
    }
}
