<?php

namespace App\Services\Fulfillment\Support;

use App\Enums\Fulfillment\ProviderCode;

/**
 * A provider's configuration as loaded for one operation: non-secret settings plus decrypted secrets.
 *
 * Secrets are reachable only through secret()/hasSecret(); they are excluded from var_dump/print_r
 * (`__debugInfo`) and from `toDisplayArray()`, the only shape the admin UI and logs may receive.
 */
final class ProviderConfig
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $credentials
     */
    public function __construct(
        public readonly ProviderCode $provider,
        public readonly bool $enabled,
        public readonly string $baseUrl,
        private readonly array $settings,
        private readonly array $credentials,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->settings[$key] ?? null;

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->settings[$key] ?? [];

        return is_array($value) ? array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== '')) : [];
    }

    public function secret(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /** Safe for the UI and logs: settings only, plus which secrets are present (never their values). */
    public function toDisplayArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'enabled' => $this->enabled,
            'base_url' => $this->baseUrl,
            'settings' => $this->settings,
            'secrets_configured' => array_map(static fn ($value): bool => is_string($value) && $value !== '', $this->credentials),
        ];
    }

    public function __debugInfo(): array
    {
        return ['provider' => $this->provider->value, 'enabled' => $this->enabled, 'credentials' => '[hidden]'];
    }
}
