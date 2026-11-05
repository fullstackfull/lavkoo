<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\Readiness\GoLiveAdmission;
use App\Services\Fulfillment\Readiness\GoLiveItem;
use App\Services\Fulfillment\Readiness\GoLiveOverride;
use App\Services\Fulfillment\Readiness\GoLiveReadiness;
use Illuminate\Support\Facades\DB;

/**
 * Saves what an administrator submitted on a provider's page, and decides whether it may make automatic sending live.
 *
 * Automatic sending becomes live when a save turns the provider on, sending on, dry run off and automatic sending on all at once. When a
 * save would do that, everything submitted is stored first with automatic sending held off, then the go-live checklist is judged on the
 * state that really exists (so a prerequisite the same request removes counts against it, and one it restores counts for it), and only
 * then is automatic sending switched on. All of it is one transaction under the provider's row lock: nobody else can change the settings
 * between the judgement and the switch.
 */
class ProviderSettingsSaver
{
    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly GoLiveReadiness $readiness,
    ) {
    }

    /**
     * @param array{enabled: bool, baseUrl: ?string, settings: array<string, mixed>, secrets: array<string, string>, clearSecrets: list<string>} $submitted
     */
    public function save(ProviderCode $provider, array $submitted, ?GoLiveOverride $override = null): GoLiveAdmission
    {
        return DB::transaction(function () use ($provider, $submitted, $override): GoLiveAdmission {
            IntegrationSetting::where('provider', $provider->value)->lockForUpdate()->first();
            $stored = $this->connections->config($provider);

            $becomesLive = !ProviderConnectionManager::isLive($stored->enabled, $stored->settings())
                && ProviderConnectionManager::isLive($submitted['enabled'], array_replace($stored->settings(), $submitted['settings']));
            if (!$becomesLive) {
                $this->write($provider, $submitted, $submitted['settings']);

                return new GoLiveAdmission();
            }

            $this->write($provider, $submitted, array_replace($submitted['settings'], ['auto_dispatch' => false]));
            $unmet = $this->readiness->report($provider)->unmet();
            if ($unmet !== [] && $override === null) {
                return new GoLiveAdmission(blocked: $unmet);
            }

            $unmetKeys = array_map(static fn (GoLiveItem $item): string => $item->key, $unmet);
            $this->connections->save(
                provider: $provider,
                enabled: null,
                baseUrl: null,
                settings: ['auto_dispatch' => true, 'go_live_override' => $unmet === [] ? null : $override->record($unmetKeys)],
                auditContext: $unmet === [] ? [] : ['go_live_gate_overridden' => $unmetKeys, 'go_live_override_reason' => $override->reason],
            );

            return new GoLiveAdmission(overridden: $unmetKeys);
        });
    }

    /**
     * @param array{enabled: bool, baseUrl: ?string, settings: array<string, mixed>, secrets: array<string, string>, clearSecrets: list<string>} $submitted
     * @param array<string, mixed> $settings
     */
    private function write(ProviderCode $provider, array $submitted, array $settings): void
    {
        $this->connections->save($provider, $submitted['enabled'], $submitted['baseUrl'], $settings, $submitted['secrets'], $submitted['clearSecrets']);
    }
}
