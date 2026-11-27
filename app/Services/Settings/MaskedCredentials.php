<?php

namespace App\Services\Settings;

/**
 * What the legacy third-party forms show where a credential is stored, and how a form that was shown a mask gives the credential back
 * unchanged (G-16).
 *
 * The payment-gateway and SMS screens render every value of `addon_settings.live_values` into an input, through
 * `showDemoModeInputValue()`, which blanks them in demo mode and hands back the real value everywhere else. So on a live installation the
 * API keys, secrets and auth tokens of every configured gateway are in the HTML of one panel page: in the page source, in a screenshot, in
 * whatever the browser caches, and readable by any employee whose role reaches the third-party module.
 *
 * This replaces the VALUE a credential field is shown with, and nothing else. The field, its label, its validation and the form around it
 * are untouched, and a value that is not a credential -- a gateway title, a sender id, a supported-country list, a status -- is shown as it
 * always was, because masking those would hide configuration an operator has to be able to read.
 *
 * ## The mask has to survive the save
 *
 * Both screens write the whole submitted set into `live_values` and `test_values`. A masked field posts the mask, so masking alone would
 * replace every credential with asterisks the first time anybody pressed Save on any other field. `restore()` is the other half: a value
 * that came back as the mask is put back from the row it was read from, so saving a form nobody edited changes nothing. A credential the
 * row does not hold is restored as empty rather than as the mask, because the mask is not a secret and must never become one.
 *
 * ## What it does not do
 *
 * The values stay PLAINTEXT in `addon_settings`. Encrypting them at rest is the other half of G-16 and is not done here: the column holds
 * live credentials on every existing installation, so turning on encryption means rewriting those rows, and a rewrite that goes wrong, or
 * an `APP_KEY` that later changes, locks an operator out of their own payment gateway. That is a migration with an owner's decision in it,
 * not a switch. The per-company accounts of `payment_gateway_accounts` already store ciphertext only
 * ({@see \App\Services\Payments\GatewayAccountSecrets}), which is where this is heading.
 *
 * `gcc.staff.credentials.masked` is the switch and it ships OFF: every value is shown exactly as it is shown today and `restore()` hands
 * the submitted set straight back, so not one key of it moves.
 */
final class MaskedCredentials
{
    /**
     * What a masked field shows and posts back.
     *
     * Plain ASCII asterisks, because the value travels through an HTML attribute, a form post and a JSON column, and a character that any
     * one of those re-encodes would stop matching itself and the credential would be overwritten with the mask.
     */
    public const MASK = '********';

    /**
     * The parts of a key name that make a value a credential.
     *
     * A list of names could not work: each gateway invents its own (`api_key`, `secret_key`, `auth_token`, `account_sid`, `hash_key`), and
     * a name this class had never seen would be rendered in full -- which is the defect. The rule is the other way round, so an unknown
     * gateway's secret is masked by default and only a name that is plainly not a secret is shown.
     */
    private const CREDENTIAL_NAME_PARTS = [
        'key', 'secret', 'token', 'password', 'passwd', 'auth', 'sid', 'signature', 'hash', 'credential', 'private', 'salt', 'pin',
    ];

    /**
     * Names that contain one of the parts above and are NOT credentials, so that a form keeps showing what an operator has to read.
     *
     * Each is here because a screen shows it: the title of a gateway, the public half of a key pair, and the identifiers a support call
     * quotes. A public key is deliberately shown -- it is published to the customer's browser by the gateway's own script.
     */
    private const NOT_CREDENTIALS = ['public_key', 'publishable_key', 'key_name', 'gateway_title', 'authorized_url', 'auth_url'];

    public function isEnabled(): bool
    {
        return (bool) config('gcc.staff.credentials.masked', false);
    }

    /** Whether a value stored under this key is a credential rather than configuration an operator reads. */
    public function isCredential(string $key): bool
    {
        $name = strtolower(trim($key));

        if (in_array($name, self::NOT_CREDENTIALS, true)) {
            return false;
        }

        foreach (self::CREDENTIAL_NAME_PARTS as $part) {
            if (str_contains($name, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value a form shows for one stored key: the mask for a credential that is set, and the value itself for everything else.
     *
     * A credential that is not set yet shows nothing, exactly as it does today, so an empty field still means "no credential" and the
     * operator is not asked to guess whether the asterisks are a value.
     */
    public function shown(string $key, mixed $value): mixed
    {
        if (!$this->isEnabled() || !is_string($value) || $value === '' || !$this->isCredential($key)) {
            return $value;
        }

        return self::MASK;
    }

    /**
     * The submitted set with every masked credential put back from the row it was read from.
     *
     * Returns the set it was given, key for key, while the switch is off -- the same array, not a rebuilt one -- so a form save is the save
     * it is today.
     *
     * @param array<string, mixed> $submitted the values the form posted
     * @param array<string, mixed>|null $stored `live_values` of the row being written, or null when there is no row yet
     * @return array<string, mixed>
     */
    public function restore(array $submitted, ?array $stored): array
    {
        if (!$this->isEnabled()) {
            return $submitted;
        }

        foreach ($submitted as $key => $value) {
            if ($value !== self::MASK || !$this->isCredential((string) $key)) {
                continue;
            }

            $kept = $stored[(string) $key] ?? null;
            $submitted[$key] = is_string($kept) ? $kept : '';
        }

        return $submitted;
    }
}
