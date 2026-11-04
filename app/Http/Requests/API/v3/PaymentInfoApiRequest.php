<?php

namespace App\Http\Requests\API\v3;

use App\Models\WithdrawalMethod;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PaymentInfoApiRequest extends FormRequest
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
        $rules = [
            'method_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vendor_withdraw_method_infos', 'method_name')->ignore($this->input('id')),
            ],
            'withdraw_method_id' => 'required|exists:withdrawal_methods,id',
        ];

        if ($this->input('id')) {
            $rules['id'] = 'exists:vendor_withdraw_method_infos,id';
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'method_name.required' => translate('The_method_name_field_is_required'),
            'method_name.string' => translate('The_method_name_must_be_a_string'),
            'method_name.max' => translate('The_method_name_must_not_exceed_255_characters'),
            'method_name.unique' => translate('The_method_name_has_already_been_taken'),
            'withdraw_method_id.required' => translate('The_withdraw_method_field_is_required'),
            'withdraw_method_id.exists' => translate('The_selected_withdraw_method_is_invalid'),
            'id.exists' => translate('The_payment_info_id_is_invalid'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $fields = WithdrawalMethod::where(['id' => $this['withdraw_method_id']])->first();

                if (!$fields) {
                    $validator->errors()->add(
                        'withdraw_method_id', translate('The_selected_withdraw_method_is_invalid')
                    );
                }

                foreach ($fields['method_fields'] as $field) {
                    if (isset($field['is_required']) && $field['is_required'] == 1)
                        if (!isset($this['method_info'][$field['input_name']]) || empty($this['method_info'][$field['input_name']])) {
                            $validator->errors()->add(
                                'method_info_' . $field['input_name'], translate('The_' . $field['input_name'] . '_field_is_required')
                            );
                        }
                }

            }
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
