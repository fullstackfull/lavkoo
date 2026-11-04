<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somewhere a seller wants to be told when something happens.
 *
 * `disabled` is the platform's answer to an endpoint that has stopped answering, and is a different
 * state from `paused`, which is the seller's. Retrying a dead endpoint for ever is how a queue fills
 * up with deliveries nobody will ever receive.
 */
class SellerWebhook extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DISABLED = 'disabled';

    public const SELLER_SETTABLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED];

    /** After this many consecutive failures the endpoint is presumed gone. */
    public const FAILURE_LIMIT = 10;

    protected $fillable = [
        'seller_id',
        'name',
        'url',
        'events',
        'secret',
        'status',
    ];

    protected $casts = [
        'events' => 'array',
        'consecutive_failures' => 'integer',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /** The signing secret is a credential. It is shown when the endpoint is created, and not again. */
    protected $hidden = ['secret'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(SellerWebhookDelivery::class, 'webhook_id');
    }

    public function wants(string $event): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && is_array($this->events)
            && in_array($event, $this->events, true);
    }
}
