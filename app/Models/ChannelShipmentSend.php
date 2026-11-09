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
    /**
     * **THE STATE COLUMN SAYS WHAT HAPPENED TO THE GOODS, NOT WHO IS WORKING ON IT.**
     *
     * Those were one column until a review found the hole: ownership was a state (`claimed`), so a row being worked read as a
     * row nothing had been sent for. A worker that died between `shipment/create` leaving this host and PHP recording that it
     * had left would leave the row `claimed`; once its lease expired, the next worker read "no send has left", asked noon, got a
     * 404, and sent a SECOND create. Two vans, through a gap of milliseconds.
     *
     * So ownership lives in `claim_token` and `claimed_at` ALONE, and the state below is only ever about the send. A lease can
     * expire, be taken over, or be abandoned without anybody forgetting that a create may already be at noon.
     */
    public const NOT_SENT = 'not_sent';

    /**
     * A send may have reached the channel — **written BEFORE the request leaves, not after it returns**.
     *
     * That order is the whole point. The row is durable the moment the attempt becomes possible, so a process that dies at any
     * instant from here on leaves a row that forbids a second send. It is reset to `not_sent` only on PROOF that the channel did
     * not process the request: a definitive refusal, a rate limit, a credential refusal, a request that never left, a rehearsal.
     */
    public const MAY_HAVE_SENT = 'may_have_sent';

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

    /**
     * True while another attempt owns this reference.
     *
     * It reads the claim and NOTHING ELSE. Tying it to the state is what let a row being worked look like a row with no send
     * behind it; ownership expiring must never be able to clear what is known about the goods.
     */
    public function claimIsLive(): bool
    {
        if ($this->claim_token === null || $this->claimed_at === null) {
            return false;
        }

        return $this->claimed_at->gt(now()->subMinutes(max(1, (int) config('sales_channels.shipment_sends.claim_minutes', 10))));
    }

    /**
     * True once a send may have reached the channel: from here on nothing is sent again without a person.
     *
     * It survives an expired lease, a crash and a takeover, because it is a fact about the goods rather than about a process.
     */
    public function mayHaveReachedTheChannel(): bool
    {
        return in_array($this->state, [self::MAY_HAVE_SENT, self::NEEDS_REVIEW], true);
    }
}
