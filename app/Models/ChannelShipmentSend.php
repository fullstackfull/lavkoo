<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * This system's own record of telling a channel that goods shipped: one row per shipment reference, and the memory of whether a send ever left.
 *
 * @property int $id
 * @property int $account_id
 * @property string $channel
 * @property string $reference
 * @property string $external_order_id
 * @property string $state
 * @property string|null $claim_token
 * @property int $attempts
 * @property string|null $reason_code
 */
class ChannelShipmentSend extends Model
{
    public const CLAIMED = 'claimed';

    /** The attempt ended with PROOF nothing left: the channel refused it outright, or a switch stopped it before the send. */
    public const NOT_SENT = 'not_sent';

    /** A send may have reached the channel. It is never repeated automatically, whatever the channel answers afterwards. */
    public const SENT = 'sent';

    public const CONFIRMED = 'confirmed';

    /** A send may have left and the channel does not show the shipment. Automation stops; a person decides. */
    public const NEEDS_REVIEW = 'needs_review';

    protected $table = 'channel_shipment_sends';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SalesChannelAccount::class, 'account_id');
    }

    /** True while another attempt owns this reference. A claim whose process died is given up after the lease. */
    public function claimIsLive(): bool
    {
        if ($this->state !== self::CLAIMED || $this->claim_token === null || $this->claimed_at === null) {
            return false;
        }

        return $this->claimed_at->gt(now()->subMinutes(max(1, (int) config('sales_channels.shipment_sends.claim_minutes', 10))));
    }

    /** True once a send may have reached the channel: from here on nothing is sent again without a person. */
    public function mayHaveReachedTheChannel(): bool
    {
        return in_array($this->state, [self::SENT, self::NEEDS_REVIEW], true);
    }
}
