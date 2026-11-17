<?php

namespace App\Models;

use App\Services\DigitalFileAddressService;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Class OrderDetail
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $product_id
 * @property int|null $seller_id
 * @property string|null $digital_file_after_sell
 * @property string|null $product_details
 * @property int $qty
 * @property float $price
 * @property float $tax
 * @property float $discount
 * @property string $tax_model
 * @property string $delivery_status
 * @property string $payment_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int|null $shipping_method_id
 * @property string|null $variant
 * @property string|null $variation
 * @property string|null $discount_type
 * @property bool $is_stock_decreased
 * @property int|null $refund_request
 * @property Carbon|null $refund_started_at
 *
 * @package App\Models
 */
class OrderDetail extends Model
{
    use StorageTrait;

    protected $fillable = [
        'product_id',
        'order_id',
        'product_details',
        'price',
        'discount',
        'qty',
        'tax',
        'tax_model',
        'digital_file_after_sell',
        'discount',
        'discount_type',
        'is_stock_decreased',
        'delivery_status',
        'payment_status',
        'shipping_method_id',
        'seller_id',
        'refund_request',
        'refund_started_at',
        'variant',
        'variation',
        'updated_at'
    ];

    protected $casts = [
        'product_id' => 'integer',
        'order_id' => 'integer',
        'price' => 'float',
        'discount' => 'float',
        'qty' => 'integer',
        'tax' => 'float',
        'shipping_method_id' => 'integer',
        'digital_file_after_sell' => 'string',
        'seller_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'refund_request' => 'integer',
        'refund_started_at' => 'datetime',
        'variation' => 'array',
    ];

    /** The in-house shop's lines carry seller_id 1 too, so the line's own seller_id is not enough: its order must be a seller's order. */
    public function scopeOfSeller($query, int|string $sellerId): void
    {
        $query->where('seller_id', $sellerId)->whereHas('order', fn ($orderQuery) => $orderQuery->where('seller_is', 'seller'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->where('status', 1);
    }

    //active_product
    public function activeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class)->where('status', 1);
    }

    //product_all_status
    public function productAllStatus(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->with(['clearanceSale' => function ($query) {
            return $query->active();
        }]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function refundStatus(): BelongsTo
    {
        return $this->belongsTo(RefundStatus::class, 'refund_request');
    }

    public function refundRequest(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'order_details_id', 'id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(ShippingAddress::class, 'shipping_address');
    }

    //verification_images
    public function verificationImages(): HasMany
    {
        return $this->hasMany(OrderDeliveryVerification::class, 'order_id', 'order_id');
    }

    public function orderStatusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id', 'order_id');
    }

    public function orderEditHistory()
    {
        return $this->hasMany(OrderEditHistory::class, 'order_id', 'order_id');
    }

    public function latestEditHistory()
    {
        return $this->hasOne(OrderEditHistory::class, 'order_id', 'order_id')->latestOfMany();
    }

    public function getDigitalFileAfterSellFullUrlAttribute(): string|null|array
    {
        $storageType = $this->digitalFileAfterSellStorageType();

        return app(DigitalFileAddressService::class)->privateAnswer(name: $this->digital_file_after_sell, kind: 'order-line', id: $this->id, storageType: $storageType)
            ?? $this->storageLink('product/digital-product', $this->digital_file_after_sell, $storageType);
    }

    /** The product of the line the way it was when the order was placed. */
    public function productSnapshot(): array
    {
        $snapshot = $this->product_details;

        return is_array($snapshot) ? $snapshot : (array) json_decode((string) $snapshot, true);
    }

    /** Whether the line delivers the ready file its snapshot names (otherwise it delivers the file that was uploaded after the sale). */
    public function deliversTheReadyFile(): bool
    {
        $product = $this->productSnapshot();

        return ($product['digital_product_type'] ?? null) == 'ready_product' && !empty($product['digital_file_ready']);
    }

    /** The name of the file the line delivers: the ready file its snapshot names, or the file uploaded after the sale. */
    public function deliveredDigitalFile(): ?string
    {
        return $this->deliversTheReadyFile() ? $this->productSnapshot()['digital_file_ready'] : $this->digital_file_after_sell;
    }

    public function digitalFileAfterSellStorageType(): string
    {
        return $this->storage->where('key', 'digital_file_after_sell')->first()['value'] ?? 'public';
    }

    protected $with = ['storage'];
    protected $appends = ['digital_file_after_sell_full_url'];

    protected static function boot(): void
    {
        parent::boot();
        static::saved(function ($model) {
            if ($model->isDirty('digital_file_after_sell')) {
                $storage = config('filesystems.disks.default') ?? 'public';
                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'digital_file_after_sell',
                ], [
                    'value' => $storage,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            cacheRemoveByType(type: 'order_details');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'order_details');
        });
    }
}
