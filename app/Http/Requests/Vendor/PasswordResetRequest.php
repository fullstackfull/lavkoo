<?php

namespace App\Http\Requests\Vendor;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordResetRequest extends FormRequest
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
            'identity' => 'required',
        ];
    }
    public function messages(): array
    {
        $verificationBy = getWebConfig('vendor_forgot_password_method') ?? 'phone';
        return [
            'identity.required' => $verificationBy == 'email' ? translate('Please_enter_email_address') : translate('Please_enter_phone_number')
        ];
    }
}
