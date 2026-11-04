<?php

namespace App\Http\Requests\Admin;

use App\Models\EmergencyContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EmergencyContactRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        return [
            'name' => 'required',
            'country_code' => 'required',
            'phone' => 'required|max:20|min:4'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('name_is_required'),
            'country_code.required' => translate('country_code_is_required'),
            'phone.required' => translate('phone_is_required'),
            'phone.max' => translate('please_ensure_your_phone_number_is_valid_and_does_not_exceed_20_characters'),
            'phone.min' => translate('phone_number_with_a_minimum_length_requirement_of_4_characters'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $id = $this->route('id');
                $exists = EmergencyContact::where('country_code', $this->input('country_code'))
                    ->where('phone', $this->input('phone'))
                    ->where('user_id', auth('admin')->id())
                    ->when($id, function ($query) use ($id) {
                        $query->where('id', '!=', $id);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'phone',
                        translate('this_phone_number_already_exists_for_you')
                    );
                }
            }
        ];
    }

}
