<?php

namespace App\Models;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order (or one shipment package of an order) as a sales channel knows it, and the application's record of what it is doing
 * about it. `order_id` is filled only when the application takes over fulfilment and an internal order is created for it.
 *
 * @property int $id
 * @property int $account_id
 * @property string $sales_channel
 * @property string $external_order_id
 * @property string $external_package_id
 * @property string $status
 * @property int|null $order_id
 */
class ExternalOrderMapping extends Model
{
    public const LINK_UNLINKED = 'unlinked';
    public const LINK_LINKED = 'linked';
    public const LINK_NEEDS_REVIEW = 'needs_review';
    public const LINK_CONFLICT = 'conflict';
    public const LINK_NOT_APPLICABLE = 'not_applicable';

    protected $table = 'external_order_mappings';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'seller_id' => 'integer',
        'order_id' => 'integer',
        'items_total' => 'decimal:6',
        'discount_total' => 'decimal:6',
        'shipping_total' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'order_total' => 'decimal:6',
        'purchased_at' => 'datetime',
        'external_updated_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'shipment_checked_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SalesChannelAccount::class, 'account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExternalOrderLine::class, 'mapping_id');
    }

    public function orderStatus(): ChannelOrderStatus
    {
        return ChannelOrderStatus::tryFrom((string) $this->status) ?? ChannelOrderStatus::New;
    }

    public function fulfillmentMode(): FulfillmentMode
    {
        return FulfillmentMode::tryFrom((string) $this->fulfillment_mode) ?? FulfillmentMode::Unknown;
    }

    public function executionPolicy(): ?ExecutionPolicy
    {
        return ExecutionPolicy::tryFrom((string) $this->execution_policy);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
