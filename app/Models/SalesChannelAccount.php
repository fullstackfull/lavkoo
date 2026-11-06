<?php

namespace App\Models;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One connected account on a sales channel (an Amazon seller account in one region, a Trendyol seller).
 *
 * Credentials live in `credentials` as an encrypted blob and are reachable only through ChannelCredentialStore: the column is
 * hidden from every serialisation, and no accessor on this model returns it.
 *
 * @property int $id
 * @property string $channel
 * @property string $name
 * @property int $seller_id
 * @property string $environment
 * @property string|null $market
 * @property string|null $external_account_id
 * @property array|null $marketplace_ids
 * @property bool $enabled
 * @property bool $write_enabled
 * @property bool $dry_run
 * @property array|null $capabilities
 * @property array|null $capability_approvals
 * @property array|null $settings
 */
class SalesChannelAccount extends Model
{
    use StoresLocalTime;

    protected $table = 'sales_channel_accounts';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected $casts = [
        'seller_id' => 'integer',
        'marketplace_ids' => 'array',
        'enabled' => 'boolean',
        'write_enabled' => 'boolean',
        'dry_run' => 'boolean',
        'capabilities' => 'array',
        'capability_approvals' => 'array',
        'settings' => 'array',
        'granted_permissions' => 'array',
        'missing_permissions' => 'array',
        'connection_checked_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function channelCode(): ?SalesChannel
    {
        return SalesChannel::tryFrom($this->channel);
    }

    public function connectionStatus(): ConnectionStatus
    {
        return ConnectionStatus::tryFrom((string) $this->connection_status) ?? ConnectionStatus::Untested;
    }

    public function executionPolicy(): ExecutionPolicy
    {
        return ExecutionPolicy::tryFrom((string) $this->execution_policy) ?? ExecutionPolicy::ManualReview;
    }

    public function hasCapability(IntegrationCapability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function scopeForChannel(Builder $query, SalesChannel|string $channel): Builder
    {
        return $query->where('channel', $channel instanceof SalesChannel ? $channel->value : $channel);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(SalesChannelProductMapping::class, 'account_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrderMapping::class, 'account_id');
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(SalesChannelSyncState::class, 'account_id');
    }
}
