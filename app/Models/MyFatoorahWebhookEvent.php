<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single webhook delivery from MyFatoorah. Written before anything is acted on, and unique on
 * `dedupe_key` so a retried delivery cannot be processed twice.
 */
class MyFatoorahWebhookEvent extends Model
{
    protected $table = 'myfatoorah_webhook_events';

    protected $guarded = ['id'];

    protected $casts = [
        'event_code' => 'integer',
        'event_created_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const EVENT_PAYMENT_STATUS_CHANGED = 1;
    public const EVENT_REFUND_STATUS_CHANGED = 2;
    public const EVENT_BALANCE_TRANSFERRED = 3;
    public const EVENT_SUPPLIER_STATUS_CHANGED = 4;
    public const EVENT_RECURRING_UPDATES = 5;
    public const EVENT_DISPUTE_STATUS_CHANGED = 6;
    public const EVENT_SUPPLIER_UPDATE_REQUEST_CHANGED = 7;
}
