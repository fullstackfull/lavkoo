<?php

namespace App\Models;

use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Class Review
 *
 * @property int $id
 * @property int $product_id
 * @property int $customer_id
 * @property int|null $delivery_man_id
 * @property int|null $order_id
 * @property string|null $comment
 * @property array|null $attachment
 * @property int $rating
 * @property int $status
 * @property bool $is_saved
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 * */
class Review extends Model
{
    use StorageTrait;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'product_id',
        'customer_id',
        'delivery_man_id',
        'order_id',
        'comment',
        'attachment',
        'rating',
        'status',
        'is_saved',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'rating' => 'integer',
        'status' => 'integer',
        'is_saved' => 'boolean',
        'attachment' => 'array',
    ];

    public function scopeActive($query): mixed
    {
        return $query->where('status', 1);
    }

    public function user():HasOne
    {
        return $this->hasOne('App\User', 'id', 'customer_id');
    }

    public function product():BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function deliveryMan(): BelongsTo
    {
        return $this->belongsTo(DeliveryMan::class, 'delivery_man_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(ReviewReply::class, 'id', 'review_id');
    }

    public function getAttachmentFullUrlAttribute(): array|null
    {
        $images = [];
        $value = $this->attachment;
        if ($value) {
            foreach ($value as $item) {
                $item = isset($item['file_name']) ? $item : ['file_name' => $item, 'storage' => 'public'];
                $images[] = $this->storageLink('review', $item['file_name'], $item['storage'] ?? 'public');
            }
        }
        return $images;
    }
    protected $appends = ['attachment_full_url'];

    /** The panels and their APIs see every review. A storefront page that merely starts with the same letters, such as /vendor-shop, does not. */
    private static function isPanelRequest(): bool
    {
        return request()->is('admin', 'admin/*', 'seller', 'seller/*', 'vendor', 'vendor/*', 'api/v2', 'api/v2/*', 'api/v3', 'api/v3/*');
    }

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('active', function (Builder $builder) {
            return self::isPanelRequest() ? $builder : $builder->where('status', 1);
        });

        static::saved(function ($model) {
            cacheRemoveByType(type: 'reviews');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'reviews');
        });
    }
}
