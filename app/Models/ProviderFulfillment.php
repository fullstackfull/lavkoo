<?php

namespace App\Models;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The provider-side child of a customer's parent order: the part of one order that a single provider
 * (M5AZN or Torod Point) ships. Has its own reference, status, tracking, attempts and API history.
 *
 * Not to be confused with OrderFulfillment, the in-house pick/pack overlay.
 *
 * @property int $id
 * @property string $reference
 * @property int $order_id
 * @property string $provider
 * @property int $generation
 * @property string $idempotency_key
 * @property FulfillmentStatus $status
 * @property string|null $hold_reason
 * @property PaymentMode $payment_mode
 * @property string $cod_amount
 * @property string|null $provider_reference
 */
class ProviderFulfillment extends Model
{
    protected $table = 'provider_fulfillments';

    protected $guarded = [];

    protected $casts = [
        'order_id' => 'integer',
        'generation' => 'integer',
        'status' => FulfillmentStatus::class,
        'payment_mode' => PaymentMode::class,
        'cod_amount' => 'decimal:2',
        'items_total' => 'decimal:2',
        'shipping_allocation' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'provider_cost' => 'decimal:2',
        'attempts' => 'integer',
        'reconcile_attempts' => 'integer',
        'claimed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'submitted_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'returned_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancel_intent_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'cancel_next_at' => 'datetime',
        'cancel_attempts' => 'integer',
        'last_event_at' => 'datetime',
        'last_received_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Provider event times carry milliseconds (the column is timestamp(3)) and events can land within the same second. The
     * model's default date format would drop the fraction when saving, so two events 400 ms apart would compare as equal and
     * an older one could overwrite a newer one.
     */
    protected function lastEventAt(): Attribute
    {
        return Attribute::make(set: static fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s.v'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProviderFulfillmentItem::class, 'provider_fulfillment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderFulfillmentEvent::class, 'provider_fulfillment_id');
    }

    public function providerCode(): ?ProviderCode
    {
        return ProviderCode::tryFrom($this->provider);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * A cancellation is a REQUEST until the provider reports the shipment cancelled, so it lives beside the
     * status rather than being one: the status keeps following the provider's truth (a shipment already on the
     * road keeps moving until the provider says otherwise). This is true once the provider ACKNOWLEDGED it.
     */
    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null && !$this->isTerminal();
    }

    /** Automatic cancellation cannot succeed (unsupported, refused, or kept failing): a person must act at the provider. */
    public function isCancelBlocked(): bool
    {
        return $this->cancel_blocked_reason !== null && !$this->isTerminal();
    }

    /**
     * Cancellation is wanted, the provider is known to hold the shipment, and it has not been asked yet (or the
     * last ask failed and is waiting to be repeated).
     */
    public function wantsCancel(): bool
    {
        return $this->cancel_intent_at !== null
            && $this->cancel_requested_at === null
            && $this->cancel_blocked_reason === null
            && $this->status->isAcceptedByProvider()
            && !$this->isTerminal();
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], $key, $default);
    }

    public function scopeForProvider(Builder $query, ProviderCode|string $provider): Builder
    {
        return $query->where('provider', $provider instanceof ProviderCode ? $provider->value : $provider);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', FulfillmentStatus::activeValues());
    }
}
