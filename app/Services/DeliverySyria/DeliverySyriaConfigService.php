<?php

namespace App\Services\DeliverySyria;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Reads and writes the single `delivery_syria` business setting that holds the optional courier
 * integration's configuration.
 *
 * Storage follows the codebase convention for admin-editable third-party credentials — a JSON blob in
 * business_settings, read through getWebConfig() and written through the BusinessSetting repository so
 * the config cache is cleared. It deviates from convention in one deliberate way: the two secrets
 * (the outbound Secret and the inbound webhook token) are encrypted at rest with the app key rather
 * than stored in clear, because the courier's own documentation requires the Secret never be exposed.
 * Decryption is transparent to callers; a value that fails to decrypt is treated as empty rather than
 * throwing, so a malformed row can never take the panel down.
 */
class DeliverySyriaConfigService
{
    public const SETTING_KEY = 'delivery_syria';

    public const DEFAULT_BASE_URL = 'https://deliverysyria.com';

    /** X-Platform value we send on outbound calls (note the spelling — it differs from the inbound one). */
    public const DEFAULT_PLATFORM = 'pharmacyesyria';

    /** X-Platform value the courier sends us on the inbound status webhook. */
    public const INBOUND_PLATFORM = 'deliverysyria';

    /** Keys whose values are secrets and are encrypted at rest. */
    private const SECRET_KEYS = ['secret_token', 'webhook_token'];

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
    ) {
    }

    /** The raw stored config as an associative array (secrets still encrypted), or [] when unset. */
    public function raw(): array
    {
        $config = getWebConfig(name: self::SETTING_KEY);

        return is_array($config) ? $config : [];
    }

    public function isEnabled(): bool
    {
        return (int) ($this->raw()['status'] ?? 0) === 1;
    }

    /** The outbound Secret (Bearer) in clear, or '' when unset/undecryptable. */
    public function secretToken(): string
    {
        return $this->decrypt($this->raw()['secret_token'] ?? null);
    }

    /** The inbound webhook Bearer token in clear, or '' when unset/undecryptable. */
    public function webhookToken(): string
    {
        return $this->decrypt($this->raw()['webhook_token'] ?? null);
    }

    public function baseUrl(): string
    {
        $url = trim((string) ($this->raw()['base_url'] ?? ''));

        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
    }

    public function platform(): string
    {
        $platform = trim((string) ($this->raw()['platform'] ?? ''));

        return $platform !== '' ? $platform : self::DEFAULT_PLATFORM;
    }

    /** A default operational field (hub_id, delivery_type_id, packaging_id, pickup_*) as a string. */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->raw()[$key] ?? null;

        return $value === null ? $default : (string) $value;
    }

    /** Enabled and has an outbound Secret — the two conditions for any outbound call to be attempted. */
    public function isReadyForOutbound(): bool
    {
        return $this->isEnabled() && $this->secretToken() !== '';
    }

    /** Enabled and has an inbound webhook token — the condition for accepting a status webhook. */
    public function isReadyForInbound(): bool
    {
        return $this->isEnabled() && $this->webhookToken() !== '';
    }

    /**
     * Persist a config update. Secret fields are encrypted here; a blank secret in the input means
     * "keep the existing one" (the panel never round-trips the stored secret back to the browser), so a
     * blank submit does not wipe a configured token. Non-secret fields overwrite.
     */
    public function save(array $input): void
    {
        $existing = $this->raw();
        $merged = $existing;

        foreach ($input as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                if ($value === null || $value === '') {
                    continue;   // keep existing encrypted secret
                }
                $merged[$key] = Crypt::encryptString((string) $value);
            } else {
                $merged[$key] = $value;
            }
        }

        $this->businessSettingRepo->updateOrInsert(type: self::SETTING_KEY, value: json_encode($merged));
    }

    private function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }
}
