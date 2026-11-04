<?php

namespace App\Http\Requests\API\v1;


use App\Enums\GlobalConstant;
use App\Rules\DisallowedExtension;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RefundStoreRequest extends FormRequest
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
            'order_details_id' => 'required|exists:order_details,id',
            'amount' => 'required|numeric|min:0',
            'refund_reason' => 'required|string',
            'images.*' => getRulesStringForImageValidation(
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
            'order_details_id.required' => translate('Order details is required.'),
            'order_details_id.exists' => translate('Invalid order details.'),
            'amount.required' => translate('Refund amount is required.'),
            'refund_reason.required' => translate('Refund reason is required.'),
            'images.*.mimes' => translate('Image type must be : '). getFileUploadFormats(skip: ['.svg'], asMessage: true),
            'images.*.max' => translate('Image size must not exceed ') . getFileUploadMaxSize() . 'MB',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
