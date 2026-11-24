<?php

namespace App\Http\Requests\Vendor;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeliveryManWithdrawRequest extends FormRequest
{
    use ResponseHandler;
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize():bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules():array
    {
        // 1 approves and 2 denies, which is what the two screens post and what the request's own column means (0 is still pending).
        // Without the bound, a posted 0 was written back as 0 -- the row stayed pending, so the same decision could be made again and
        // again, and each time released the reservation afresh: `pending_withdraw` went negative and the withdrawable balance, which is
        // the balance less the reservation, grew by the amount on every repeat.
        return [
            'approved' => 'required|in:1,2',
            'note' => 'required',
        ];
    }
    public function messages(): array
    {
        return [
            'note.required' => translate('please_type_your_note').'.',
        ];
    }
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
