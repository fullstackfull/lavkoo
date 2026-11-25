<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Foundation\Http\FormRequest;

/** The address a test e-mail is sent to and the market whose account sends it (I1-01). */
class MailAccountTestSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'email' => ['required', 'email', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required' => translate('choose_the_market_this_account_belongs_to'),
            'country.regex' => translate('choose_the_market_this_account_belongs_to'),
            'email.required' => translate('type_a_valid_email_address_to_send_the_test_to'),
            'email.email' => translate('type_a_valid_email_address_to_send_the_test_to'),
        ];
    }

    public function country(): string
    {
        return strtoupper(trim((string) $this->input('country')));
    }

    public function address(): string
    {
        return trim((string) $this->input('email'));
    }
}
