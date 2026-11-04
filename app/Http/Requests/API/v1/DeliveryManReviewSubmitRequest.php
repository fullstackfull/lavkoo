<?php

namespace App\Http\Requests\API\v1;

use App\Models\WithdrawalMethod;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeliveryManReviewSubmitRequest extends FormRequest
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
            'order_id' => 'required|exists:orders,id',
            'comment'  => 'required|string',
            'rating'   => 'required|numeric|min:1|max:5',
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => translate('Order ID is required.'),
            'order_id.exists'   => translate('Invalid order.'),
            'comment.required'  => translate('Comment is required.'),
            'rating.required'   => translate('Rating is required.'),
            'rating.min'        => translate('Rating must be at least 1.'),
            'rating.max'        => translate('Rating may not be greater than 5.'),
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
