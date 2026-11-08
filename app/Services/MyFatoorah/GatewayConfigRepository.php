<?php

namespace App\Services\MyFatoorah;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes the MyFatoorah configuration held in the `addon_settings` row keyed `fatoorah`.
 *
 * Storage shape, and why:
 *
 *   is_active           the gateway switch. The SAME column every other gateway uses, so the existing
 *                       toggle on the payment-gateway page, the storefront's `payment_gateways()` helper
 *                       and the mobile config endpoint all pick MyFatoorah up with no changes to them.
 *   mode                'test' | 'live'.
 *   test_values  }      per-mode blobs. `api_key` and `webhook_secret` inside them are stored as
 *   live_values  }      Crypt::encryptString(...), so a database dump does not leak usable credentials.
 *   additional_data     non-secret: the display title, the gateway image, the cached method catalogue
 *                       and the last connection-test result.
 *
 * Two things this class is careful about:
 *
 * 1. The generic payment-gateway form writes plaintext into both blobs at once (it saves
 *    `$validator->validate()` to live_values AND test_values). A key typed in there would arrive here
 *    unencrypted, so every read tries to decrypt and falls back to treating the value as plaintext.
 *    That keeps an operator who used the old form working instead of failing with a decrypt error.
 *
 * 2. A save never writes an empty secret over a stored one. The admin form posts blank password fields
 *    when the operator did not retype a key — which is the normal case, because the form never renders
 *    the stored value — and treating that blank as "clear the key" would silently break a live gateway.
 */
class GatewayConfigRepository
{
    /**
     * The gateway identifier. `fatoorah` is what this codebase already used everywhere, including in
     * historical `payment_requests.payment_method` values, so it stays.
     */
    public const KEY = 'fatoorah';

    /** Accepted as an alias on inbound routes so an app build or a bookmark using it still resolves. */
    public const ALIASES = ['fatoorah', 'my_fatoorah', 'myfatoorah'];

    private const SETTINGS_TYPE = 'payment_config';

    /** Keys inside a per-mode blob that are encrypted at rest and never rendered back. */
    private const SECRET_KEYS = ['api_key', 'webhook_secret'];

    private ?GatewayConfig $cached = null;

    public function forget(): void
    {
        $this->cached = null;
    }

    public function get(): GatewayConfig
    {
        return $this->cached ??= $this->load();
    }

    /** The configuration for a specific mode, whatever the row's current mode is. Used by the connection test. */
    public function forMode(string $mode): GatewayConfig
    {
        return $this->load($mode === 'live' ? 'live' : 'test');
    }

    private function load(?string $forceMode = null): GatewayConfig
    {
        $row = $this->row();

        $mode = $forceMode ?? (($row && $row->mode === 'live') ? 'live' : 'test');
        $values = $this->decodeValues($row, $mode);
        $additional = $this->decodeAdditional($row);
        $apiKey = $this->readSecret($values, 'api_key');

        return new GatewayConfig(
            activeInAdmin: (bool) (int) ($row->is_active ?? 0),
            mode: $mode,
            apiKey: $apiKey,
            webhookSecret: $this->readSecret($values, 'webhook_secret'),
            baseUrl: $this->baseUrlFor($mode),
            currency: strtoupper((string) config('myfatoorah.currency', 'SAR')),
            title: (string) ($additional['gateway_title'] ?? 'MyFatoorah'),
            enabledMethods: array_values(array_filter((array) ($additional['enabled_methods'] ?? []), 'is_string')),
            accountMethods: is_array($additional['account_methods'] ?? null) ? $additional['account_methods'] : [],
            lastTestAt: $additional['last_test_at'][$mode] ?? null,
            lastTestResult: $additional['last_test_result'][$mode] ?? null,
            lastTestMessage: $additional['last_test_message'][$mode] ?? null,
            accountStatus: $additional['account_status'][$mode] ?? null,
            invoiceLookupProof: $this->invoiceLookupProof($additional, $mode, $apiKey),
        );
    }

    public function row(): ?object
    {
        return DB::table('addon_settings')
            ->where('key_name', self::KEY)
            ->where('settings_type', self::SETTINGS_TYPE)
            ->first();
    }

    /**
     * The host for a mode, pinned to the allow-list.
     *
     * A settings row is editable by anyone with admin access to the database, and the one thing an
     * attacker would want from it is to point the API key at a host of their own. The configured host is
     * therefore checked against `myfatoorah.allowed_hosts` and, if it is not there, the documented
     * default is used instead of it.
     */
    private function baseUrlFor(string $mode): string
    {
        $configured = (string) config('myfatoorah.hosts.' . $mode, '');
        $allowed = (array) config('myfatoorah.allowed_hosts', []);

        $host = parse_url($configured, PHP_URL_HOST);
        $scheme = parse_url($configured, PHP_URL_SCHEME);

        if ($scheme === 'https' && is_string($host) && in_array($host, $allowed, true)) {
            return rtrim($configured, '/');
        }

        return $mode === 'live' ? 'https://api-sa.myfatoorah.com' : 'https://apitest.myfatoorah.com';
    }

    private function decodeValues(?object $row, string $mode): array
    {
        if (!$row) {
            return [];
        }

        $raw = $mode === 'live' ? ($row->live_values ?? null) : ($row->test_values ?? null);

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeAdditional(?object $row): array
    {
        $decoded = json_decode((string) ($row->additional_data ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One stored secret. Encrypted values are the normal case; a plaintext one is accepted because the
     * shared gateway form may have written it, and rejecting it would strand the operator.
     */
    private function readSecret(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            // Written by the shared form, or by hand. Usable, just not encrypted yet; the next save
            // through this class stores it encrypted.
            return $value;
        }

        return $plain === '' ? null : $plain;
    }

    /**
     * Persist a change.
     *
     * @param array<string,mixed>       $settings    non-secret values merged into additional_data
     * @param array<string,string|null> $secrets     per-mode secrets; a null or empty value KEEPS the stored one
     * @param string|null               $secretsMode which blob $secrets belongs to; defaults to the saved mode
     */
    public function save(
        ?bool $active = null,
        ?string $mode = null,
        array $settings = [],
        array $secrets = [],
        ?string $secretsMode = null,
        array $clearSecrets = [],
    ): void {
        $row = $this->row();
        $now = now();

        $targetMode = $secretsMode ?? $mode ?? (($row && $row->mode === 'live') ? 'live' : 'test');
        $targetMode = $targetMode === 'live' ? 'live' : 'test';

        $live = $this->decodeValues($row, 'live');
        $test = $this->decodeValues($row, 'test');
        $additional = $this->decodeAdditional($row);

        $blob = $targetMode === 'live' ? $live : $test;

        foreach (self::SECRET_KEYS as $secretKey) {
            $given = $secrets[$secretKey] ?? null;

            if (is_string($given) && trim($given) !== '') {
                $blob[$secretKey] = Crypt::encryptString(trim($given));
            }
        }

        foreach ($clearSecrets as $secretKey) {
            if (in_array($secretKey, self::SECRET_KEYS, true)) {
                unset($blob[$secretKey]);
            }
        }

        // Keep the shape the shared gateway page expects to find in these blobs.
        $blob['status'] = $active === null ? ($blob['status'] ?? 0) : (int) $active;
        $blob['mode'] = $mode ?? ($blob['mode'] ?? $targetMode);

        if ($targetMode === 'live') {
            $live = $blob;
        } else {
            $test = $blob;
        }

        $additional = array_replace($additional, $settings);
        $additional['gateway_title'] = $additional['gateway_title'] ?? 'MyFatoorah — ماي فاتورة السعودية';
        $additional['gateway_image'] = $additional['gateway_image'] ?? '';

        $update = [
            'live_values' => json_encode($live, JSON_UNESCAPED_UNICODE),
            'test_values' => json_encode($test, JSON_UNESCAPED_UNICODE),
            'additional_data' => json_encode($additional, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        if ($mode !== null) {
            $update['mode'] = $mode === 'live' ? 'live' : 'test';
        }

        if ($active !== null) {
            $update['is_active'] = (int) $active;
        }

        if ($row) {
            DB::table('addon_settings')->where('id', $row->id)->update($update);
        } else {
            DB::table('addon_settings')->insert(array_merge($update, [
                'id' => (string) Str::uuid(),
                'key_name' => self::KEY,
                'settings_type' => self::SETTINGS_TYPE,
                'mode' => $update['mode'] ?? 'test',
                'is_active' => $update['is_active'] ?? 0,
                'created_at' => $now,
            ]));
        }

        $this->forget();
    }

    /** Record the outcome of a connection test, per mode, for the admin panel to display. */
    public function recordTest(string $mode, bool $ok, string $message, ?string $accountStatus = null): void
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        $additional = $this->decodeAdditional($this->row());

        // Each of these is a map keyed by mode, so a live test result never overwrites a sandbox one.
        foreach (['last_test_at', 'last_test_result', 'last_test_message', 'account_status'] as $bucket) {
            if (!is_array($additional[$bucket] ?? null)) {
                $additional[$bucket] = [];
            }
        }

        $additional['last_test_at'][$mode] = now()->toIso8601String();
        $additional['last_test_result'][$mode] = $ok ? 'ok' : 'failed';
        // Truncated and already safe: the client strips bodies and headers before messages reach here.
        $additional['last_test_message'][$mode] = Str::limit($message, 200, '');
        $additional['account_status'][$mode] = $accountStatus;

        $this->save(settings: $additional, secretsMode: $mode);
    }

    /**
     * Record that ONE invoice-lookup path answered, on the connection in use now.
     *
     * Until a path has answered, a 404 from it proves nothing: MyFatoorah publishes no literal path for
     * "Get Invoice by InvoiceId" or "Get Invoice by ExternalIdentifier", so the 404 is as likely to mean
     * "no such path" or "no permission" as "no such invoice". Once that path has answered once, a later
     * 404 from THE SAME path on THE SAME connection really does mean the invoice is not there.
     *
     * Both halves of that sentence are load-bearing, and an earlier version of this method had neither:
     * it recorded one timestamp per mode, so the "Get Invoice by InvoiceId" path answering marked the
     * "by ExternalIdentifier" path proven as well — and a later 404 from a path that had never worked
     * was then read as "no invoice exists", which authorises a second invoice for an order that may
     * already have one. The same went for a change of API key or host: the proof outlived the account it
     * was earned on.
     */
    public function markInvoiceLookupProven(string $mode, string $endpointKey): void
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        $row = $this->row();
        $additional = $this->decodeAdditional($row);
        $apiKey = $this->readSecret($this->decodeValues($row, $mode), 'api_key');
        $fingerprint = $this->connectionFingerprint($mode, $apiKey, $endpointKey);

        if (!is_array($additional['invoice_lookup_proof'] ?? null)) {
            $additional['invoice_lookup_proof'] = [];
        }

        if (!is_array($additional['invoice_lookup_proof'][$mode] ?? null)) {
            $additional['invoice_lookup_proof'][$mode] = [];
        }

        $existing = $additional['invoice_lookup_proof'][$mode][$endpointKey] ?? null;

        if (is_array($existing) && hash_equals($fingerprint, (string) ($existing['connection'] ?? ''))) {
            // Already proven for this exact connection. Nothing to write, and no point taking a write
            // lock on the settings row on every sweep.
            return;
        }

        // One entry per path per mode: a new connection replaces the old proof rather than adding to it,
        // which keeps the blob bounded and errs towards "not proven" if the key is ever changed back.
        $additional['invoice_lookup_proof'][$mode][$endpointKey] = [
            'at' => now()->toIso8601String(),
            'connection' => $fingerprint,
        ];

        // The per-mode shape this replaced. Dropped rather than migrated: it cannot say WHICH path
        // answered, and that is precisely what makes it unusable as proof.
        unset($additional['invoice_lookup_proven_at']);

        $this->save(settings: $additional, secretsMode: $mode);
    }

    /**
     * Which invoice-lookup paths have been seen to work on the connection in use now.
     *
     * A stored proof whose fingerprint no longer matches is ignored — not deleted, just not believed —
     * so switching the API key back restores nothing and the path has to answer again.
     *
     * @param array<string, mixed> $additional
     *
     * @return array<string, string> endpoint key => when it last answered
     */
    private function invoiceLookupProof(array $additional, string $mode, ?string $apiKey): array
    {
        $stored = $additional['invoice_lookup_proof'][$mode] ?? null;

        if (!is_array($stored)) {
            return [];
        }

        $proven = [];

        foreach ($stored as $endpointKey => $entry) {
            if (!is_string($endpointKey) || !is_array($entry)) {
                continue;
            }

            $at = $entry['at'] ?? null;
            $connection = $entry['connection'] ?? null;

            if (!is_string($at) || $at === '' || !is_string($connection) || $connection === '') {
                continue;
            }

            if (!hash_equals($this->connectionFingerprint($mode, $apiKey, $endpointKey), $connection)) {
                continue;
            }

            $proven[$endpointKey] = $at;
        }

        return $proven;
    }

    /**
     * The identity of the connection a lookup proof belongs to.
     *
     * Everything that decides what a request to this path hits, and who it is sent as: the host, the
     * path template (env-overridable, so it can change under us) and the API key for the mode. Change
     * any of them and the proof is void — a different key may be a different merchant with different
     * permissions, and a different path is simply a different request.
     *
     * Hashed, because the API key is one of the inputs and this value is stored in `addon_settings`
     * alongside the rest of the non-secret configuration.
     */
    private function connectionFingerprint(string $mode, ?string $apiKey, string $endpointKey): string
    {
        return hash('sha256', implode("\n", [
            $mode,
            $this->baseUrlFor($mode),
            (string) config('myfatoorah.endpoints.' . $endpointKey, ''),
            (string) $apiKey,
        ]));
    }

    /** Cache the catalogue of methods the account actually has enabled. Non-secret. */
    public function storeAccountMethods(array $methods): void
    {
        $this->save(settings: ['account_methods' => $methods, 'account_methods_fetched_at' => now()->toIso8601String()]);
    }
}
