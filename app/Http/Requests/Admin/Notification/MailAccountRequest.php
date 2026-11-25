<?php

namespace App\Http\Requests\Admin\Notification;

use App\Services\Notification\NotificationAccountWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One market's mail account as an operator typed it (I1-01).
 *
 * The secrets are `nullable` on purpose and it is the heart of this screen: the form is never shown the stored password, so a save that only
 * changes the host arrives with an empty password field. An empty secret therefore means "leave it alone" and the writer keeps what is
 * stored; clearing one is the separate `forget[]` instruction, which an operator has to ask for.
 */
class MailAccountRequest extends FormRequest
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
            'host' => ['nullable', 'string', 'max:191'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'driver' => ['nullable', 'string', Rule::in(['smtp', 'sendmail', 'mail'])],
            'encryption' => ['nullable', 'string', Rule::in(['tls', 'ssl'])],
            'from_address' => ['nullable', 'email', 'max:191'],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:191'],
            'active' => ['nullable', 'boolean'],
            'forget' => ['nullable', 'array'],
            'forget.*' => ['string', Rule::in(NotificationAccountWriter::MAIL_CREDENTIALS)],
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
            'from_address.email' => translate('the_from_address_is_not_an_email_address'),
            'port.integer' => translate('the_port_is_a_number'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'driver' => $this->input('driver'),
            'host' => $this->input('host'),
            'port' => $this->input('port'),
            'encryption' => $this->input('encryption'),
            'from_address' => $this->input('from_address'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function secrets(): array
    {
        return ['username' => (string) $this->input('username', ''), 'password' => (string) $this->input('password', '')];
    }

    /**
     * @return list<string>
     */
    public function forget(): array
    {
        return array_values(array_filter(
            (array) $this->input('forget', []),
            static fn (mixed $name): bool => in_array($name, NotificationAccountWriter::MAIL_CREDENTIALS, true),
        ));
    }

    public function country(): string
    {
        return strtoupper(trim((string) $this->input('country')));
    }

    public function isActive(): bool
    {
        return (bool) $this->input('active', false);
    }

    public function label(): ?string
    {
        $label = trim((string) $this->input('label', ''));

        return $label === '' ? null : $label;
    }
}
