<?php

namespace App\Http\Requests\API\v1;

use App\Models\WithdrawalMethod;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SupportTicketRequest extends FormRequest
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
            'subject' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'description' => 'required|string',
            'image.*' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => translate('Subject is required.'),
            'type.required' => translate('Type is required.'),
            'description.required' => translate('Description is required.'),
            'image.*.mimes' => translate('Invalid image format_image_type must be : ') . getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'image.*.max' => translate('Image size exceeds_maximum_size'). ' ' . getFileUploadMaxSize() . 'MB',
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
