<?php

namespace App\Services\SalesChannels;

use App\Enums\SalesChannels\AccountEnvironment;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Support\ChannelSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates channel accounts and moves them, one deliberate step at a time, from "disabled" to a working integration.
 *
 * A new account does nothing: it is disabled, has no capability, may not write, and is in dry run. Each step is separate and logged:
 *   1. enable the account;
 *   2. switch on capabilities in order (connection test, read-only sync, then product sync / order import / financial reconciliation...);
 *      a capability needs the ones before it, and is refused while the connection has not been proven;
 *   3. a capability that changes something at the channel (publishing stock, publishing prices, dispatching fulfilment) additionally needs
 *      a recorded APPROVAL by a person, with a reason, after a successful connection test;
 *   4. only then may writing be allowed on the account, and dry run turned off.
 * Switching a capability off switches off those that depend on it and withdraws their approvals.
 */
class ChannelAccountService
{
    /** How recent the successful connection test must be for a write capability to be approved. */
    public const CONNECTION_FRESH_DAYS = 30;

    /** Approvals a person can record besides the write capabilities themselves. */
    public const EXTRA_SCOPES = [ChannelOperations::SCOPE_PRODUCT_WRITES, ChannelOperations::SCOPE_WEBHOOK_MANAGEMENT, ChannelOperations::SCOPE_NOTIFICATION_MANAGEMENT];

    public function __construct(
        private readonly ChannelCredentialStore $credentials,
        private readonly AuditLogger $audit,
        private readonly InventoryLocationService $locations,
    ) {
    }

    /**
     * @param array<string, mixed> $data channel, name, [environment, market, external_account_id, currency, execution_policy, seller_id, settings]
     * @param array<string, string|null> $secrets
     */
    public function create(array $data, array $secrets, ?int $adminId): SalesChannelAccount
    {
        $channel = SalesChannel::tryFrom((string) ($data['channel'] ?? ''));
        if ($channel === null || !$channel->isExternal()) {
            throw new InvalidArgumentException('Choose Amazon or Trendyol.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('The account needs a name.');
        }
        $environment = AccountEnvironment::tryFrom((string) ($data['environment'] ?? 'production')) ?? throw new InvalidArgumentException('The environment is production or sandbox.');
        $settings = ChannelSettings::validate($channel->value, (array) ($data['settings'] ?? []));

        $account = DB::transaction(function () use ($channel, $name, $environment, $data, $settings, $adminId): SalesChannelAccount {
            return SalesChannelAccount::create([
                'channel' => $channel->value,
                'name' => mb_substr($name, 0, 120),
                'seller_id' => (int) ($data['seller_id'] ?? 0),
                'environment' => $environment->value,
                'market' => ($data['market'] ?? null) ?: $this->marketFor($channel->value, $settings),
                'external_account_id' => ($data['external_account_id'] ?? null) ?: null,
                'marketplace_ids' => $this->marketplaceIdsFor($channel->value, $settings),
                'currency' => ($data['currency'] ?? null) ? strtoupper((string) $data['currency']) : null,
                'enabled' => false,
                'write_enabled' => false,
                'dry_run' => true,
                'capabilities' => [],
                'capability_approvals' => [],
                'execution_policy' => ExecutionPolicy::tryFrom((string) ($data['execution_policy'] ?? ''))?->value ?? ExecutionPolicy::ManualReview->value,
                'settings' => $settings,
                'connection_status' => ConnectionStatus::Untested->value,
                'created_by' => $adminId,
            ]);
        });

        $changed = $this->credentials->save($account, $secrets);
        if ($channel === SalesChannel::Amazon) {
            $this->locations->ensureFbaLocation($account);
        }
        $this->audit->record(action: 'sales_channel.account_created', subject: $account, context: ['channel' => $channel->value, 'secrets_set' => $changed, 'by' => $adminId]);

        return $account;
    }

    /**
     * @param array<string, mixed> $data name, environment, market, external_account_id, currency, execution_policy, settings
     * @param array<string, string|null> $secrets
     * @param list<string> $clearSecrets
     */
    public function update(SalesChannelAccount $account, array $data, array $secrets, array $clearSecrets, ?int $adminId): SalesChannelAccount
    {
        $before = $account->only(['name', 'environment', 'market', 'external_account_id', 'currency', 'execution_policy', 'settings']);
        $identityBefore = $this->identityOf($account);
        $account->fill(array_filter([
            'name' => isset($data['name']) ? mb_substr(trim((string) $data['name']), 0, 120) : null,
            'environment' => isset($data['environment']) ? (AccountEnvironment::tryFrom((string) $data['environment'])?->value ?? throw new InvalidArgumentException('The environment is production or sandbox.')) : null,
            'market' => $data['market'] ?? null,
            'external_account_id' => $data['external_account_id'] ?? null,
            'currency' => isset($data['currency']) && $data['currency'] !== '' ? strtoupper((string) $data['currency']) : null,
            'execution_policy' => isset($data['execution_policy']) ? (ExecutionPolicy::tryFrom((string) $data['execution_policy'])?->value ?? throw new InvalidArgumentException('Unknown execution policy.')) : null,
        ], static fn ($value): bool => $value !== null));
        if (isset($data['settings'])) {
            $account->settings = ChannelSettings::validate($account->channel, array_replace($account->settings ?? [], (array) $data['settings']));
            // The connectors read these two columns, not the settings: they follow the settings, whatever the form sent.
            $account->marketplace_ids = $this->marketplaceIdsFor($account->channel, $account->settings);
            if (!isset($data['market'])) {
                $account->market = $this->marketFor($account->channel, $account->settings) ?? $account->market;
            }
        }
        $credentialsChanged = $secrets !== [] || $clearSecrets !== [];
        $account->updated_by = $adminId;
        $account->save();

        $changed = $this->credentials->save($account, $secrets, $clearSecrets);
        if ($credentialsChanged && $changed !== []) {
            // New credentials have not been proven: the account must be tested again before anything leans on the old result.
            $this->forgetConnection($account);
        }
        if ($this->identityOf($account) !== $identityBefore) {
            $this->resetForNewIdentity($account, $adminId);
        }
        $this->audit->record(action: 'sales_channel.account_updated', subject: $account, before: $before, after: $account->only(array_keys($before)), context: ['secrets_changed' => $changed, 'by' => $adminId]);

        return $account;
    }

    /**
     * Which seller, where. Anything that approved or proved something about this account was approved or proved for THIS identity.
     *
     * @return array<string, mixed>
     */
    private function identityOf(SalesChannelAccount $account): array
    {
        $marketplaces = array_map('strval', (array) ($account->marketplace_ids ?? []));
        sort($marketplaces);

        return ['seller' => (string) $account->external_account_id, 'environment' => (string) $account->environment, 'market' => (string) $account->market, 'marketplaces' => $marketplaces];
    }

    /**
     * The account now points at a different seller, environment or market. The last test, every approval and the write permission described the old one, so they are
     * withdrawn: only the connection test stays switched on, writes are forbidden, dry run is back on, and each step has to be earned again for the new identity.
     */
    private function resetForNewIdentity(SalesChannelAccount $account, ?int $adminId): void
    {
        $this->forgetConnection($account);
        $account->forceFill([
            'capabilities' => array_values(array_intersect($this->held($account), [IntegrationCapability::ConnectionTest->value])),
            'capability_approvals' => [],
            'write_enabled' => false,
            'dry_run' => true,
        ])->save();
        $this->audit->record(action: 'sales_channel.identity_changed', subject: $account, context: ['by' => $adminId, 'withdrawn' => 'connection result, approvals, write permission and every step above the connection test']);
    }

    private function forgetConnection(SalesChannelAccount $account): void
    {
        $account->forceFill(['connection_status' => ConnectionStatus::Untested->value, 'connection_checked_at' => null, 'connection_message' => null, 'granted_permissions' => [], 'missing_permissions' => []])->save();
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function marketplaceIdsFor(string $channel, array $settings): array
    {
        if ($channel === SalesChannel::Amazon->value) {
            return array_values((array) ($settings['marketplace_ids'] ?? []));
        }

        return ($settings['storefront_code'] ?? '') === '' ? [] : [(string) $settings['storefront_code']];
    }

    /** @param array<string, mixed> $settings */
    private function marketFor(string $channel, array $settings): ?string
    {
        $market = $channel === SalesChannel::Amazon->value ? ($settings['region'] ?? null) : ($settings['storefront_code'] ?? null);

        return $market === '' ? null : $market;
    }

    public function setEnabled(SalesChannelAccount $account, bool $enabled, ?int $adminId): SalesChannelAccount
    {
        $account->forceFill(['enabled' => $enabled, 'updated_by' => $adminId])->save();
        $this->audit->record(action: $enabled ? 'sales_channel.account_enabled' : 'sales_channel.account_disabled', subject: $account, context: ['by' => $adminId]);

        return $account;
    }

    /**
     * @throws ChannelUnavailableException when a prerequisite is missing; the reason says which
     */
    public function enableCapability(SalesChannelAccount $account, IntegrationCapability $capability, ?int $adminId, ?string $approvalReason = null): SalesChannelAccount
    {
        $held = $this->held($account);
        foreach ($capability->requires() as $prerequisite) {
            if (!in_array($prerequisite->value, $held, true)) {
                throw new ChannelUnavailableException(ChannelUnavailableException::CAPABILITY_OFF, "Switch on '{$prerequisite->value}' first.");
            }
        }
        if ($capability !== IntegrationCapability::ConnectionTest && !$account->connectionStatus()->isUsable()) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Run a successful connection test first.');
        }

        $approvals = (array) ($account->capability_approvals ?? []);
        if ($capability->needsApproval()) {
            if (trim((string) $approvalReason) === '') {
                throw new ChannelUnavailableException(ChannelUnavailableException::NOT_APPROVED, "'{$capability->value}' changes things at the channel: a person must approve it, with a reason.");
            }
            if ($account->connection_checked_at === null || $account->connection_checked_at->lt(now()->subDays(self::CONNECTION_FRESH_DAYS))) {
                throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The last successful connection test is too old: test the connection again before approving a write.');
            }
            if ($account->missing_permissions !== null && $account->missing_permissions !== []) {
                throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The account is missing permissions at the channel: ' . implode(', ', $account->missing_permissions) . '.');
            }
            $approvals[$capability->value] = ['by' => $adminId, 'at' => now()->toIso8601String(), 'reason' => mb_substr(trim((string) $approvalReason), 0, 500)];
        }

        $held[] = $capability->value;
        $account->forceFill(['capabilities' => array_values(array_unique($held)), 'capability_approvals' => $approvals, 'updated_by' => $adminId])->save();
        $this->audit->record(action: 'sales_channel.capability_enabled', subject: $account, context: ['capability' => $capability->value, 'by' => $adminId, 'reason' => $approvalReason]);

        return $account;
    }

    /** Switches a capability off, and with it every capability that depends on it, withdrawing their approvals. */
    public function disableCapability(SalesChannelAccount $account, IntegrationCapability $capability, ?int $adminId): SalesChannelAccount
    {
        $off = [$capability->value];
        do {
            $grew = false;
            foreach (IntegrationCapability::cases() as $candidate) {
                if (!in_array($candidate->value, $off, true) && array_intersect(array_map(static fn (IntegrationCapability $item): string => $item->value, $candidate->requires()), $off) !== []) {
                    $off[] = $candidate->value;
                    $grew = true;
                }
            }
        } while ($grew);

        $approvals = (array) ($account->capability_approvals ?? []);
        foreach ($off as $value) {
            unset($approvals[$value]);
        }
        $account->forceFill([
            'capabilities' => array_values(array_diff($this->held($account), $off)),
            'capability_approvals' => $approvals,
            'updated_by' => $adminId,
        ])->save();
        $this->audit->record(action: 'sales_channel.capability_disabled', subject: $account, context: ['capabilities' => $off, 'by' => $adminId]);

        return $account;
    }

    /** Records a person's approval of a kind of write that is not itself a capability (pushing product content, managing webhooks). */
    public function approveScope(SalesChannelAccount $account, string $scope, int $adminId, string $reason): SalesChannelAccount
    {
        if (!in_array($scope, self::EXTRA_SCOPES, true)) {
            throw new InvalidArgumentException("'{$scope}' is not a kind of write that is approved on its own.");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An approval needs a reason: it is kept in the audit trail.');
        }
        if (!$account->connectionStatus()->isUsable()) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Run a successful connection test first.');
        }
        $approvals = (array) ($account->capability_approvals ?? []);
        $approvals[$scope] = ['by' => $adminId, 'at' => now()->toIso8601String(), 'reason' => mb_substr(trim($reason), 0, 500)];
        $account->forceFill(['capability_approvals' => $approvals])->save();
        $this->audit->record(action: 'sales_channel.scope_approved', subject: $account, context: ['scope' => $scope, 'by' => $adminId, 'reason' => $reason]);

        return $account;
    }

    public function revokeScope(SalesChannelAccount $account, string $scope, ?int $adminId): SalesChannelAccount
    {
        $approvals = (array) ($account->capability_approvals ?? []);
        unset($approvals[$scope]);
        $account->forceFill(['capability_approvals' => $approvals])->save();
        $this->audit->record(action: 'sales_channel.scope_revoked', subject: $account, context: ['scope' => $scope, 'by' => $adminId]);

        return $account;
    }

    /** Allows (or forbids) this account to change things at the channel at all. Allowing needs a proven connection. */
    public function setWriteEnabled(SalesChannelAccount $account, bool $allowed, ?int $adminId): SalesChannelAccount
    {
        if ($allowed && !$account->connectionStatus()->isUsable()) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Run a successful connection test first.');
        }
        $account->forceFill(['write_enabled' => $allowed, 'updated_by' => $adminId])->save();
        $this->audit->record(action: $allowed ? 'sales_channel.writes_allowed' : 'sales_channel.writes_forbidden', subject: $account, context: ['by' => $adminId]);

        return $account;
    }

    /** Dry run: a write is logged as the request it would be and never sent. Turning it OFF is the last step before real writes. */
    public function setDryRun(SalesChannelAccount $account, bool $dryRun, ?int $adminId): SalesChannelAccount
    {
        $account->forceFill(['dry_run' => $dryRun, 'updated_by' => $adminId])->save();
        $this->audit->record(action: $dryRun ? 'sales_channel.dry_run_on' : 'sales_channel.dry_run_off', subject: $account, context: ['by' => $adminId]);

        return $account;
    }

    public function recordConnection(SalesChannelAccount $account, ConnectionTestResult $result): SalesChannelAccount
    {
        $account->forceFill([
            'connection_status' => $result->status->value,
            'connection_checked_at' => now(),
            'connection_message' => mb_substr($result->message, 0, 500),
            'granted_permissions' => $result->granted,
            'missing_permissions' => $result->missing,
            'external_account_id' => $result->externalAccountId ?? $account->external_account_id,
            'last_error' => $result->ok() ? null : mb_substr($result->message, 0, 500),
        ])->save();
        $this->audit->record(action: 'sales_channel.connection_tested', subject: $account, context: ['status' => $result->status->value, 'missing' => $result->missing]);

        return $account;
    }

    /** The account's credentials were refused mid-work (a token expired, a seller withdrew authorisation). Everything waits for a fresh authorisation. */
    public function markAuthorisationLost(SalesChannelAccount $account, string $message, ConnectionStatus $status = ConnectionStatus::AuthExpired): void
    {
        $account->forceFill(['connection_status' => $status->value, 'connection_message' => mb_substr($message, 0, 500), 'last_error' => mb_substr($message, 0, 500)])->save();
        $this->audit->record(action: 'sales_channel.authorisation_lost', subject: $account, context: ['status' => $status->value]);
    }

    /** @return list<string> */
    private function held(SalesChannelAccount $account): array
    {
        return array_values(array_map('strval', (array) ($account->capabilities ?? [])));
    }
}
