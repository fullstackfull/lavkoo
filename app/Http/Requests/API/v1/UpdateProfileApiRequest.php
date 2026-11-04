<?php

namespace App\Http\Requests\API\v1;

use App\Models\WithdrawalMethod;
use App\Traits\ResponseHandler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileApiRequest extends FormRequest
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
        $userId = $this->user()->id;

        return [
            'f_name' => ['required', 'string', 'max:255'],
            'l_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'email' => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['nullable', 'string', 'min:6'],
            'image' => getRulesStringForImageValidation(
                rules: ['nullable','image'],
                skipMimes: ['.svg','.gif'],
                maxSize: getFileUploadMaxSize(unit: 'kb'),
                isDisallowed: true
            )
        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => translate('First name is required!'),
            'l_name.required' => translate('Last name is required!'),
            'phone.required' => translate('Phone is required!'),
            'phone.unique' => translate('Phone_already_exists'),
            'email.email' => translate('Invalid_email_format'),
            'email.unique' => translate('Email_already_exists'),
            'password.min' => translate('Password_must_be_at_least_6_characters'),
            'image.image' => translate('Invalid_image_file'),
            'image.max' => translate('Image_size_must_not_exceed') . ' ' . getFileUploadMaxSize() . ' MB.',
            'image.mimes' => translate('Invalid_image_format_type_must_be') . getFileUploadFormats(skip: ['.svg','.gif'], asMessage: true),
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
