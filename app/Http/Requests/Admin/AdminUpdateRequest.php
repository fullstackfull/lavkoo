<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateRequest extends FormRequest
{
    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required',
            'role_id' => 'required',
            'email' => [
                'required',
                'email',
                Rule::unique('admins', 'email')->ignore($this->route('id')),
            ],
            'image' => getRulesStringForImageValidation(
                rules: ['sometimes', 'image'],
                skipMimes: ['.svg', '.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
            'identity_image.*' => getRulesStringForImageValidation(
                rules: ['nullable'],
                skipMimes: ['.svg', '.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            ),
        ];
        if ($this['password']) {
            $rules['password'] = 'required|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*\W)(?!.*\s).{8,}$/|same:confirm_password';
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => translate('name_is_required'),
            'role_id.required' => translate('role_id_is_required'),
            'email.required' => translate('email_is_required'),
            'email.email' => translate('email_must_be_valid'),
            'email.unique' => translate('email_already_taken'),
            'identity_image.*.mimes' => translate('each_identity_image_must_be_a_file_of_type_') . getFileUploadFormats(skip: ['.svg', '.gif'], asMessage: true),
            'identity_image.*.max' => translate('each_identity_image_may_not_be_greater_than_'). getFileUploadMaxSize() . "MB",
            'image.mimes' => translate('The_image_type_must_be') . getFileUploadFormats(skip: ['.svg','.gif'], asMessage: 'true'),
            'image.max' => translate('The_image_may_not_be_greater_than_'). getFileUploadMaxSize() . "MB",
            'password.regex' => translate('The_password_must_be_at_least_8_characters_long_and_contain_at_least_one_uppercase_letter').','.translate('_one_lowercase_letter').','.translate('_one_digit_').','.translate('_one_special_character').','.translate('_and_no_spaces').'.',
        ];
    }

}
