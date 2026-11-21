<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * A company's gateway credentials, per account and per environment (D2-08).
 *
 * This is App\Services\MyFatoorah\GatewayConfigRepository's treatment of a secret, generalised to every gateway and to one account rather than one
 * platform-wide settings row: a value is written with Crypt::encryptString and is never written anywhere else, so a dump of
 * `payment_gateway_accounts` is of no use to anybody. There is deliberately no plaintext column beside the ciphertext, and nothing here puts a value
 * into a log line, an exception message or a response: a secret that cannot be read is reported as absent, never as its own contents.
 *
 * Test and live cannot cross. An account row IS one environment, and a secret is read from the row of the environment asked for, so there is no blob
 * either value could be fetched from by mistake — which is the defect the generic admin form has today, writing one validated set of values into both
 * live_values and test_values.
 *
 * Nothing here answers "the secret of company A" to a caller holding company B's account, because the account is the argument: a request acting for
 * company B resolves company B's account (App\Services\Payments\GatewayAccountRegistry) and can reach no other company's row through this class.
 *
 * Where a company's secrets live is the owner's decision (D2-08). This stores them encrypted in the account row with the application key, which is the
 * mechanism this project already uses and needs no key management it does not have; an external secret store would need one and is recorded as pending
 * rather than invented here.
 */
final class GatewayAccountSecrets
{
    /** The credential names a gateway account may hold. A name not listed is not stored, so a stray form field cannot become a credential. */
    public const NAMES = ['api_key', 'secret_key', 'public_key', 'hmac', 'integration_id', 'merchant_id'];

    /** The webhook signing secret, which lives in its own column because a webhook address resolves it without reading the credentials. */
    public const WEBHOOK_SECRET = 'webhook_secret';

    /**
     * One stored credential of this account, or null when it holds none.
     *
     * A value that will not decrypt is reported as absent. The alternative — handing back the ciphertext, as GatewayConfigRepository does for the
     * plaintext the shared form may have written — would mean a gateway called with a wrong credential and a 401 from the provider instead of a clear
     * "not configured"; an account row of this table is only ever written through this class, so there is no legacy plaintext to strand.
     */
    public function read(PaymentGatewayAccount $account, string $name): ?string
    {
        if (!in_array($name, self::NAMES, true)) {
            return null;
        }

        $stored = $this->blob(account: $account)[$name] ?? null;

        return is_string($stored) ? $this->decrypt(stored: $stored) : null;
    }

    public function has(PaymentGatewayAccount $account, string $name): bool
    {
        return $this->read(account: $account, name: $name) !== null;
    }

    /** The signing secret a notification for this account is authenticated with, or null when it has none: an account with none authenticates nothing. */
    public function webhookSecret(PaymentGatewayAccount $account): ?string
    {
        $stored = $account->getAttribute(self::WEBHOOK_SECRET);

        return is_string($stored) && $stored !== '' ? $this->decrypt(stored: $stored) : null;
    }

    /**
     * Which credentials this account holds, by name only. For an admin screen that has to say whether an account is configured without ever rendering
     * what it is configured with.
     *
     * @return list<string>
     */
    public function namesHeld(PaymentGatewayAccount $account): array
    {
        $held = [];

        foreach (self::NAMES as $name) {
            if ($this->has(account: $account, name: $name)) {
                $held[] = $name;
            }
        }

        if ($this->webhookSecret(account: $account) !== null) {
            $held[] = self::WEBHOOK_SECRET;
        }

        return $held;
    }

    /**
     * Writes credentials for this account, encrypted. A name that is not a credential of a gateway account is ignored; a blank or null value KEEPS the
     * stored one, because the admin form posts blank fields for the credentials the operator did not retype and reading that blank as "clear it" would
     * silently stop a live gateway. Clearing one is asked for by name.
     *
     * @param array<string, string|null> $secrets
     * @param list<string>               $clear
     */
    public function put(PaymentGatewayAccount $account, array $secrets, array $clear = []): void
    {
        $blob = $this->blob(account: $account);

        foreach ($secrets as $name => $value) {
            if (in_array($name, self::NAMES, true) && is_string($value) && trim($value) !== '') {
                $blob[$name] = Crypt::encryptString(trim($value));
            }
        }

        foreach ($clear as $name) {
            unset($blob[$name]);
        }

        $account->setAttribute('credentials', $blob === [] ? null : json_encode($blob, JSON_UNESCAPED_SLASHES));

        if (array_key_exists(self::WEBHOOK_SECRET, $secrets)) {
            $given = $secrets[self::WEBHOOK_SECRET];

            if (is_string($given) && trim($given) !== '') {
                $account->setAttribute(self::WEBHOOK_SECRET, Crypt::encryptString(trim($given)));
            }
        }

        if (in_array(self::WEBHOOK_SECRET, $clear, true)) {
            $account->setAttribute(self::WEBHOOK_SECRET, null);
        }

        $account->save();
    }

    /** @return array<string, mixed> */
    private function blob(PaymentGatewayAccount $account): array
    {
        $decoded = json_decode((string) $account->getAttribute('credentials'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** A value that will not decrypt is absent, and the reason is not reported: a DecryptException message is about ciphertext we do not describe. */
    private function decrypt(string $stored): ?string
    {
        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }
}
