<?php

namespace App\Http\Requests\Admin;

use App\Models\RefundRequest;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RefundStatusRequest extends FormRequest
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
        $refund = RefundRequest::where('id', $this['id'])->first();
        $data = [
            'id' => 'required',
            'refund_status' => 'required|in:pending,approved,rejected,refunded',
            'rejected_note' => $this->input('refund_status') == 'rejected' ? 'required' : '',
            'payment_method' => $this->input('refund_status') == 'refunded' ? 'required' : '',
        ];

        if (($this->input('refund_status') == 'approved' || $this->input('refund_status') == 'refunded') && empty($refund['approved_note'])) {
            $data['approved_note'] = 'required';
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'approved_note.required' => translate('The_approved_note_field_is_required'),
            'rejected_note.required' => translate('The_rejected_note_field_is_required'),
            'payment_method.required' => translate('The_payment_method_field_is_required'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
