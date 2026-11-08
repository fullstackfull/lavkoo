<?php

namespace App\Services\SalesChannels\Support;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads and writes the secrets of a channel account. They live in one encrypted blob (`Crypt::encryptString(json)`) on the account row, are
 * never returned by any query or serialisation of the account, and are shown to nobody: the admin panel is told only WHICH secrets are present.
 *
 * Only the keys a channel declares can be stored. A blank value on save keeps what is stored (a form that does not repeat a secret must not
 * wipe it), and a secret is removed only by being named for clearing. A stored blob that cannot be decrypted (the application key changed) reads as "no secrets", but it is
 * never overwritten by accident: only a complete new set replaces it, anything less is refused and the old ciphertext stays, so restoring the key still recovers it.
 * Changing a secret that identifies the authorisation drops the tokens obtained with the old one.
 */
class ChannelCredentialStore
{
    /** What each channel's account may hold. `runtime` keys are written by the system itself (tokens) and are not typed in. */
    public const SECRET_KEYS = [
        'amazon' => ['lwa_client_id', 'lwa_client_secret', 'refresh_token', 'aws_access_key_id', 'aws_secret_access_key'],
        'trendyol' => ['api_key', 'api_secret', 'webhook_api_key', 'webhook_password'],
        // The four fields of the service-account key file noon hands over once, when the account is made in its Access app.
        'noon' => ['key_id', 'private_key', 'project_code', 'channel_identifier'],
    ];

    /** What a complete set of typed credentials is, for the one case where it replaces a blob nobody can read. */
    private const COMPLETE_SET = [
        'amazon' => ['lwa_client_id', 'lwa_client_secret', 'refresh_token'],
        'trendyol' => ['api_key', 'api_secret'],
        'noon' => ['key_id', 'private_key', 'project_code'],
    ];

    public const RUNTIME_KEYS = [
        'amazon' => ['access_token', 'access_token_expires_at', 'grantless_token', 'grantless_token_expires_at'],
        'trendyol' => [],
        // The session noon's sign-in returns. Its cookie is NOT named in the documentation, so the whole cookie string is kept rather than one named value.
        'noon' => ['session_cookie', 'session_expires_at'],
    ];

    public function get(SalesChannelAccount $account): ChannelCredentials
    {
        return new ChannelCredentials($this->decrypt($account));
    }

    /**
     * @param array<string, string|null> $secrets only non-empty values replace a stored secret
     * @param list<string> $clear secrets to remove
     * @return list<string> the keys that changed (for the audit entry; never the values)
     */
    public function save(SalesChannelAccount $account, array $secrets, array $clear = []): array
    {
        $allowed = self::SECRET_KEYS[$account->channel] ?? [];

        return DB::transaction(function () use ($account, $secrets, $clear, $allowed): array {
            $row = SalesChannelAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $values = $this->decryptForWrite($row, $secrets);
            $changed = [];
            foreach ($secrets as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    throw new InvalidArgumentException("'{$key}' is not a credential of a {$account->channel} account.");
                }
                if (is_string($value) && trim($value) !== '') {
                    $values[$key] = trim($value);
                    $changed[] = $key;
                }
            }
            foreach ($clear as $key) {
                if (in_array($key, $allowed, true) && array_key_exists($key, $values)) {
                    unset($values[$key]);
                    $changed[] = $key . ':cleared';
                }
            }
            if ($changed !== []) {
                // A token was obtained with the previous secrets: it is not good for the new ones.
                $values = array_diff_key($values, array_flip(self::RUNTIME_KEYS[$account->channel] ?? []));
            }
            $this->store($row, $values);
            $account->setRawAttributes($row->getAttributes(), true);

            return $changed;
        });
    }

    /** Stores a secret the SYSTEM obtained (a token) with a row lock, so an admin save cannot overwrite it nor be overwritten by it. */
    public function putRuntime(SalesChannelAccount $account, string $key, ?string $value): void
    {
        if (!in_array($key, self::RUNTIME_KEYS[$account->channel] ?? [], true)) {
            throw new InvalidArgumentException("'{$key}' is not a runtime secret of a {$account->channel} account.");
        }
        DB::transaction(function () use ($account, $key, $value): void {
            $row = SalesChannelAccount::whereKey($account->id)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $values = $this->decryptForWrite($row, []);
            if ($value === null || $value === '') {
                unset($values[$key]);
            } else {
                $values[$key] = $value;
            }
            $this->store($row, $values);
            $this->adoptStoredCredentials(account: $account, stored: $row);
        });
    }

    /** The caller keeps using the model it passed in, so it must see the token just written; other unsaved attributes stay untouched. */
    private function adoptStoredCredentials(SalesChannelAccount $account, SalesChannelAccount $stored): void
    {
        $account->setAttribute('credentials', $stored->getAttributes()['credentials'] ?? null);
        $account->syncOriginalAttribute('credentials');
    }

    /** @param array<string, mixed> $values */
    private function store(SalesChannelAccount $row, array $values): void
    {
        $row->forceFill(['credentials' => $values === [] ? null : Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR))])->save();
    }

    /** @return array<string, mixed> what is stored; nothing when nothing is stored or it cannot be read (reads carry on without secrets, they never write) */
    private function decrypt(SalesChannelAccount $account): array
    {
        try {
            return $this->readBlob($account) ?? [];
        } catch (DecryptException|\JsonException) {
            Log::warning('Sales channel credentials could not be decrypted; treating them as unset.', ['account_id' => $account->id, 'channel' => $account->channel]);

            return [];
        }
    }

    /**
     * What is stored, as the starting point of a write. A blob that cannot be read is replaced only by a complete new set typed in at that moment; any smaller write would
     * destroy the only copy of the other secrets, which the right application key can still decrypt.
     *
     * @param array<string, string|null> $incoming
     * @return array<string, mixed>
     * @throws ChannelUnavailableException
     */
    private function decryptForWrite(SalesChannelAccount $row, array $incoming): array
    {
        try {
            return $this->readBlob($row) ?? [];
        } catch (DecryptException|\JsonException) {
            $typed = array_keys(array_filter($incoming, static fn ($value): bool => is_string($value) && trim($value) !== ''));
            if (array_diff(self::COMPLETE_SET[$row->channel] ?? [], $typed) !== []) {
                throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The stored credentials of this account cannot be decrypted with this server\'s application key. Restore the key, or enter the complete set of credentials again to replace them; nothing was changed.');
            }
            Log::warning('Sales channel credentials that could not be decrypted were replaced by a complete new set.', ['account_id' => $row->id, 'channel' => $row->channel]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null null when nothing is stored
     * @throws DecryptException
     * @throws \JsonException
     */
    private function readBlob(SalesChannelAccount $account): ?array
    {
        $blob = $account->getAttributes()['credentials'] ?? null;
        if ($blob === null || $blob === '') {
            return null;
        }
        $decoded = json_decode(Crypt::decryptString($blob), true, 8, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
