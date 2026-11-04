<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to tell somebody something.
 *
 * Kept whether it worked or not. A seller whose integration is quietly missing every third order
 * needs to see the third one, and a counter on the endpoint cannot show them which.
 */
class SellerWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /** Enough of the response to diagnose with, not enough to store somebody's whole error page. */
    public const RESPONSE_EXCERPT = 2000;

    protected $fillable = [
        'webhook_id',
        'seller_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_code',
        'response_body',
        'error',
        'next_attempt_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_code' => 'integer',
        'delivered_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];
}
