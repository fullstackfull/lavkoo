<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\IntegrationSetting;
use App\Services\AuditLogger;
use App\Services\Fulfillment\Support\ProviderConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Loads and stores each provider's connection settings.
 *
 * Same storage and encryption as the existing Chatwoot integration: one `integration_settings` row per
 * provider, secrets as `Crypt::encryptString(json)` in `credentials`, everything else plain JSON in
 * `settings`. Credentials never appear in source, in config files, in logs, or in any response — the
 * UI is told only WHICH secrets are present.
 *
 * Outbound traffic needs two independent switches: the environment's global kill switch and this
 * provider's own `enabled` flag. `canCall()` is the single place that rule lives.
 */
class ProviderConnectionManager
{
    /** Secret keys each provider accepts. Anything else submitted is ignored. */
    private const SECRET_KEYS = [
        'm5azn' => ['email', 'password', 'access_token', 'token_expires_at', 'webhook_secret'],
        // Fulfillment API v5 authenticates with headers (username/password); Lastmile API v2 with an
        // e-mail/password in the request body. Usually the same account, but documented separately.
        'torod_point' => ['fulfillment_username', 'fulfillment_password', 'lastmile_email', 'lastmile_password', 'webhook_secret'],
    ];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly AuditLogger $audit,
    ) {
    }

    public function isGloballyEnabled(): bool
    {
        return (bool) config('fulfillment.enabled', false);
    }

    /** May any outbound call be made for this provider right now? */
    public function canCall(ProviderCode $provider): bool
    {
        return $this->isGloballyEnabled() && $this->config($provider)->enabled;
    }

    public function config(ProviderCode $provider): ProviderConfig
    {
        $row = IntegrationSetting::where('provider', $provider->value)->first();

        $settings = array_replace(
            $this->registry->defaultSettings($provider),
            is_array($row?->settings) ? $row->settings : [],
        );

        $baseUrl = rtrim((string) ($row?->base_url ?: config('fulfillment.providers.' . $provider->value . '.default_base_url')), '/');

        return new ProviderConfig(
            provider: $provider,
            enabled: (bool) ($row?->enabled ?? false),
            baseUrl: $baseUrl,
            settings: $settings,
            credentials: $this->decrypt($row),
        );
    }

    /**
     * @param string|null $baseUrl null keeps the stored address; an empty string resets it to the provider's default
     * @param array<string, mixed> $settings non-secret values to merge over the stored ones
     * @param array<string, string|null> $secrets only non-empty values replace a stored secret
     * @param list<string> $clearSecrets secrets to wipe explicitly
     */
    public function save(
        ProviderCode $provider,
        bool $enabled,
        ?string $baseUrl,
        array $settings,
        array $secrets = [],
        array $clearSecrets = [],
    ): void {
        DB::transaction(function () use ($provider, $enabled, $baseUrl, $settings, $secrets, $clearSecrets): void {
            $row = IntegrationSetting::where('provider', $provider->value)->lockForUpdate()->first()
                ?? new IntegrationSetting(['provider' => $provider->value]);

            $before = $this->config($provider);

            $merged = array_replace(
                $this->registry->defaultSettings($provider),
                is_array($row->settings) ? $row->settings : [],
                $settings,
            );
            $merged = $this->applyGoLive($before->enabled, $before->settings(), $merged, $enabled);

            $allowed = self::SECRET_KEYS[$provider->value] ?? [];
            $credentials = $this->decrypt($row);
            $changedSecrets = [];
            foreach ($secrets as $key => $value) {
                if (in_array($key, $allowed, true) && is_string($value) && $value !== '') {
                    $credentials[$key] = $value;
                    $changedSecrets[] = $key;
                }
            }
            foreach ($clearSecrets as $key) {
                if (in_array($key, $allowed, true)) {
                    unset($credentials[$key]);
                    $changedSecrets[] = $key . ':cleared';
                }
            }

            $row->name = config('fulfillment.providers.' . $provider->value . '.label', $provider->value);
            $row->enabled = $enabled;
            $row->base_url = $baseUrl === null ? $row->base_url : ($baseUrl === '' ? null : rtrim($baseUrl, '/'));
            $row->settings = $merged;
            $row->credentials = $credentials === [] ? null : Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
            $row->save();

            $this->audit->record(
                action: 'integration.provider_settings_updated',
                subject: ['type' => 'integration_setting', 'id' => $row->id],
                before: ['enabled' => $before->enabled, 'settings' => $this->auditableSettings($before->settings())],
                after: ['enabled' => $enabled, 'settings' => $this->auditableSettings($merged)],
                context: ['provider' => $provider->value, 'secrets_changed' => $changedSecrets],
            );
        });
    }

    /**
     * Store a secret the SYSTEM obtained (an access token). Read-modify-write under a row lock so a
     * concurrent admin save cannot be overwritten by it, nor overwrite it.
     */
    public function putRuntimeSecret(ProviderCode $provider, string $key, ?string $value): void
    {
        DB::transaction(function () use ($provider, $key, $value): void {
            $row = IntegrationSetting::where('provider', $provider->value)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }

            $credentials = $this->decrypt($row);
            if ($value === null || $value === '') {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = $value;
            }

            $row->credentials = $credentials === [] ? null : Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
            $row->save();
        });
    }

    /** Generate a fresh webhook secret and return it ONCE; only its presence is shown afterwards. */
    public function rotateWebhookSecret(ProviderCode $provider): string
    {
        $secret = Str::random(48);
        $this->save(
            provider: $provider,
            enabled: $this->config($provider)->enabled,
            baseUrl: null,
            settings: [],
            secrets: ['webhook_secret' => $secret],
        );

        return $secret;
    }

    /** @return array<string, mixed> */
    public function defaultSettings(ProviderCode $provider): array
    {
        return $this->registry->defaultSettings($provider);
    }

    /**
     * `go_live_at` records the moment outbound dispatch last became live (enabled and not dry-run).
     * Orders placed before it are held rather than shipped retroactively when an operator flips the switch.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function applyGoLive(bool $wasEnabled, array $before, array $after, bool $enabled): array
    {
        $wasLive = $this->isLive($wasEnabled, $before);
        $willBeLive = $this->isLive($enabled, $after);

        if ($willBeLive && !$wasLive) {
            $after['go_live_at'] = now()->toDateTimeString();
        } elseif (!$willBeLive) {
            $after['go_live_at'] = null;
        }

        return $after;
    }

    /** @param array<string, mixed> $settings */
    private function isLive(bool $enabled, array $settings): bool
    {
        return $enabled
            && filter_var($settings['dispatch_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !filter_var($settings['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function auditableSettings(array $settings): array
    {
        unset($settings['field_maps'], $settings['status_map']);

        return $settings;
    }

    /** @return array<string, mixed> */
    private function decrypt(?IntegrationSetting $row): array
    {
        if ($row === null || $row->credentials === null || $row->credentials === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($row->credentials), true, 8, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (DecryptException|\JsonException) {
            Log::warning('Provider credentials could not be decrypted; treating as unset.', ['provider' => $row->provider]);

            return [];
        }
    }
}
