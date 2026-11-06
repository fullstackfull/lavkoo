<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One order line's frozen fulfillment ownership.
 *
 * Written once, inside the checkout transaction, from the product's provider and mapping AT THAT
 * MOMENT. The attributes in IMMUTABLE can never change afterwards — only the routing bookkeeping
 * (`provider_fulfillment_id`, `status`) moves. That is what makes "reassign the product later" unable
 * to redirect an order that is already placed, and what makes a line impossible to own twice
 * (`order_detail_id` is unique).
 *
 * @property int $id
 * @property int|null $provider_fulfillment_id
 * @property int $order_id
 * @property int $order_detail_id
 * @property int $product_id
 * @property string $variant_key
 * @property string $provider
 */
class ProviderFulfillmentItem extends Model
{
    public const STATUS_UNROUTED = 'unrouted';
    public const STATUS_ROUTED = 'routed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Columns frozen at creation. */
    public const IMMUTABLE = [
        'order_id', 'order_detail_id', 'product_id', 'variant_key', 'provider', 'mapping_id',
        'external_product_id', 'external_sku', 'external_barcode', 'sku', 'name', 'quantity',
        'unit_price', 'line_discount', 'line_tax', 'line_total', 'unit_cost', 'weight_kg', 'snapshot',
    ];

    protected $table = 'provider_fulfillment_items';

    protected $guarded = [];

    protected $casts = [
        'provider_fulfillment_id' => 'integer',
        'order_id' => 'integer',
        'order_detail_id' => 'integer',
        'product_id' => 'integer',
        'mapping_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:6',
        'line_discount' => 'decimal:6',
        'line_tax' => 'decimal:6',
        'line_total' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'weight_kg' => 'decimal:3',
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $item): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($item->isDirty($attribute)) {
                    throw new LogicException("Provider ownership snapshot is immutable: '{$attribute}' cannot change.");
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Provider ownership snapshots are never deleted.');
        });
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(ProviderFulfillment::class, 'provider_fulfillment_id');
    }

    public function orderDetail(): BelongsTo
    {
        return $this->belongsTo(OrderDetail::class, 'order_detail_id');
    }
}
