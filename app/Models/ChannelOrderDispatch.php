<?php

namespace App\Models;

use App\Enums\SalesChannels\DispatchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The application's record of turning one channel order into a provider shipment.
 *
 * @property int $id
 * @property int $mapping_id
 * @property int $account_id
 * @property string $state
 * @property string|null $provider
 * @property string|null $claim_token
 * @property int $attempts
 * @property int|null $internal_order_id
 */
class ChannelOrderDispatch extends Model
{
    protected $table = 'channel_order_dispatches';

    protected $guarded = [];

    protected $casts = [
        'mapping_id' => 'integer',
        'account_id' => 'integer',
        'attempts' => 'integer',
        'internal_order_id' => 'integer',
        'requested_by' => 'integer',
        'claimed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'materialized_at' => 'datetime',
        'accepted_at' => 'datetime',
        'buyer_data_purged_at' => 'datetime',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderMapping::class, 'mapping_id');
    }

    public function dispatchState(): DispatchState
    {
        return DispatchState::from($this->state);
    }

    /** Whether an attempt that claimed this order is still within its time. */
    public function claimIsLive(): bool
    {
        return $this->claim_token !== null && $this->claimed_at !== null && $this->claimed_at->gt(now()->subMinutes((int) config('sales_channels.dispatch.claim_minutes', 10)));
    }
}
