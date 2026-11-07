<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;

/** An inbound webhook or notification (or an internal event worth auditing), stored once however often it is delivered. */
class SalesChannelEvent extends Model
{
    use StoresLocalTime;

    /** A fact whose effect (a cost in the ledger, a refund on an order line) has not been applied yet. */
    public const RECEIVED = 'received';
    public const APPLIED = 'applied';
    public const NEEDS_REVIEW = 'needs_review';
    public const RECORDED_ONLY = 'recorded_only';
    /** A charge a statement row repeats that the API had already given: recorded as such, not counted. */
    public const DUPLICATE = 'duplicate';

    /** Why an event is kept for a person (`outcome_note`). */
    public const NOTE_POSSIBLE_OVERLAP = 'possible_overlap';
    public const NOTE_ORDER_NOT_HELD = 'order_not_held';
    public const NOTE_LINE_NOT_MATCHED = 'line_not_matched';
    public const NOTE_CURRENCY_DIFFERS = 'currency_differs';

    protected $table = 'sales_channel_events';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'duplicate_of_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
