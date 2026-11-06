<?php

namespace App\Services\SalesChannels\Support;

use App\Models\SalesChannelAccount;
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
 * wipe it), and a secret is removed only by being named for clearing.
 */
class ChannelCredentialStore
{
    /** What each channel's account may hold. `runtime` keys are written by the system itself (tokens) and are not typed in. */
    public const SECRET_KEYS = [
        'amazon' => ['lwa_client_id', 'lwa_client_secret', 'refresh_token', 'aws_access_key_id', 'aws_secret_access_key'],
        'trendyol' => ['api_key', 'api_secret', 'webhook_api_key', 'webhook_password'],
    ];

    public const RUNTIME_KEYS = [
        'amazon' => ['access_token', 'access_token_expires_at', 'grantless_token', 'grantless_token_expires_at'],
        'trendyol' => [],
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
            $values = $this->decrypt($row);
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
            $values = $this->decrypt($row);
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

    /** @return array<string, mixed> */
    private function decrypt(SalesChannelAccount $account): array
    {
        $blob = $account->getAttributes()['credentials'] ?? null;
        if ($blob === null || $blob === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($blob), true, 8, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (DecryptException|\JsonException) {
            Log::warning('Sales channel credentials could not be decrypted; treating them as unset.', ['account_id' => $account->id, 'channel' => $account->channel]);

            return [];
        }
    }
}
