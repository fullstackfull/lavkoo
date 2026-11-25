<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\IntegrationMode;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
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
    /**
     * Secret keys the two shipped carriers accept. Anything else submitted is ignored.
     *
     * A DEFAULT, not a gate, since H1-11: a carrier declared in `config/fulfillment.php` names its own keys under `secret_keys`, because a carrier whose
     * credentials nothing would accept could be configured and never authenticate. The two below are not in the configuration on purpose -- what a
     * shipped adapter authenticates with is part of that adapter, not a setting -- so their behaviour is byte for byte what it was.
     */
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

    /** May anything be WRITTEN to a provider from this host? Off unless FULFILLMENT_EXTERNAL_WRITES_ENABLED is on. */
    public function externalWritesEnabled(): bool
    {
        return $this->isGloballyEnabled() && (bool) config('fulfillment.external_writes_enabled', false);
    }

    /** May an order be submitted right now? A real one needs this host to be allowed to write; a dry run sends nothing. */
    public function allowsSubmission(ProviderConfig $config): bool
    {
        return $config->bool('dry_run', true) || $this->externalWritesEnabled();
    }

    public function mode(): IntegrationMode
    {
        return IntegrationMode::resolve($this->isGloballyEnabled(), (bool) config('fulfillment.external_writes_enabled', false));
    }

    /**
     * The secret keys this carrier accepts: the ones its configuration entry names, or, for the two shipped carriers, the ones written above.
     *
     * @return list<string>
     */
    private function secretKeysOf(ProviderIdentity $provider): array
    {
        $declared = config('fulfillment.providers.' . $provider->code() . '.secret_keys');

        if (is_array($declared)) {
            return array_values(array_filter($declared, static fn (mixed $key): bool => is_string($key) && $key !== ''));
        }

        return self::SECRET_KEYS[$provider->code()] ?? [];
    }

    /** May any outbound call be made for this provider right now? */
    public function canCall(ProviderIdentity $provider): bool
    {
        return $this->isGloballyEnabled() && $this->config($provider)->enabled;
    }

    public function config(ProviderIdentity $provider): ProviderConfig
    {
        $row = IntegrationSetting::where('provider', $provider->code())->first();

        $settings = array_replace(
            $this->registry->defaultSettings($provider),
            is_array($row?->settings) ? $row->settings : [],
        );

        $baseUrl = rtrim((string) ($row?->base_url ?: config('fulfillment.providers.' . $provider->code() . '.default_base_url')), '/');

        return new ProviderConfig(
            provider: $provider,
            enabled: (bool) ($row?->enabled ?? false),
            baseUrl: $baseUrl,
            settings: $settings,
            credentials: $this->decrypt($row),
        );
    }

    /**
     * @param bool|null $enabled null keeps the stored switch: a caller that only changes a map or a secret must not rewrite it from a stale read
     * @param string|null $baseUrl null keeps the stored address; an empty string resets it to the provider's default
     * @param array<string, mixed> $settings non-secret values to merge over the stored ones
     * @param array<string, string|null> $secrets only non-empty values replace a stored secret
     * @param list<string> $clearSecrets secrets to wipe explicitly
     * @param array<string, mixed> $auditContext extra facts for the audit entry of this change (for example a gate that was overridden)
     */
    public function save(
        ProviderIdentity $provider,
        ?bool $enabled,
        ?string $baseUrl,
        array $settings,
        array $secrets = [],
        array $clearSecrets = [],
        array $auditContext = [],
    ): void {
        DB::transaction(function () use ($provider, $enabled, $baseUrl, $settings, $secrets, $clearSecrets, $auditContext): void {
            $row = IntegrationSetting::where('provider', $provider->code())->lockForUpdate()->first()
                ?? new IntegrationSetting(['provider' => $provider->code()]);

            $before = $this->config($provider);
            $enabled ??= $before->enabled;

            $merged = array_replace(
                $this->registry->defaultSettings($provider),
                is_array($row->settings) ? $row->settings : [],
                $settings,
            );
            $merged = $this->applyGoLive($before->enabled, $before->settings(), $merged, $enabled);

            $allowed = $this->secretKeysOf($provider);
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

            $row->name = config('fulfillment.providers.' . $provider->code() . '.label', $provider->code());
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
                context: ['provider' => $provider->code(), 'secrets_changed' => $changedSecrets] + $auditContext,
            );
        });
    }

    /**
     * Store a secret the SYSTEM obtained (an access token). Read-modify-write under a row lock so a
     * concurrent admin save cannot be overwritten by it, nor overwrite it.
     */
    public function putRuntimeSecret(ProviderIdentity $provider, string $key, ?string $value): void
    {
        DB::transaction(function () use ($provider, $key, $value): void {
            $row = IntegrationSetting::where('provider', $provider->code())->lockForUpdate()->first();
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
    public function rotateWebhookSecret(ProviderIdentity $provider): string
    {
        $secret = Str::random(48);
        $this->save(
            provider: $provider,
            enabled: null,
            baseUrl: null,
            settings: [],
            secrets: ['webhook_secret' => $secret],
        );

        return $secret;
    }

    /** @return array<string, mixed> */
    public function defaultSettings(ProviderIdentity $provider): array
    {
        return $this->registry->defaultSettings($provider);
    }

    /**
     * `go_live_at` records the moment AUTOMATIC outbound dispatch last became live (enabled, not dry-run, and automatic sending on).
     * Orders placed before it are held rather than shipped retroactively when an operator flips the switch; an order that waited
     * for approval during the controlled phase is never swept out by the switch, an operator sends it or leaves it.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function applyGoLive(bool $wasEnabled, array $before, array $after, bool $enabled): array
    {
        $wasLive = self::isLive($wasEnabled, $before);
        $willBeLive = self::isLive($enabled, $after);

        if ($willBeLive && !$wasLive) {
            $after['go_live_at'] = now()->toDateTimeString();
        } elseif (!$willBeLive) {
            $after['go_live_at'] = null;
        }

        return $after;
    }

    /**
     * Would orders be sent without a person approving each one? Needs the provider on, sending on, automatic sending on, and
     * dry run off: the one definition the go-live timestamp and the go-live checklist both use.
     *
     * @param array<string, mixed> $settings
     */
    public static function isLive(bool $enabled, array $settings): bool
    {
        return $enabled
            && filter_var($settings['dispatch_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && filter_var($settings['auto_dispatch'] ?? false, FILTER_VALIDATE_BOOLEAN)
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
