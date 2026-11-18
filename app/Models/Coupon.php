<?php

namespace App\Models;

use App\Services\Storefront\CouponCountry;
use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $added_by
 * @property string $coupon_type
 * @property string $coupon_bearer
 * @property integer $seller_id
 * @property integer $customer_id
 * @property string $title
 * @property string $code
 * @property datetime $start_date
 * @property datetime $expire_date
 * @property float $min_purchase
 * @property float $max_discount
 * @property float $discount
 * @property string $discount_type
 * @property integer $limit
 * @property string|null $storefront_country the storefront whose currency the amounts are typed in; null is the default storefront's (GCC-05)
 */
class Coupon extends Model
{
    protected $fillable = [
        'added_by',
        'coupon_type',
        'coupon_bearer',
        'customer_id',
        'seller_id',
        'title',
        'code',
        'start_date',
        'expire_date',
        'min_purchase',
        'max_discount',
        'discount',
        'discount_type',
        'limit',
        'storefront_country',
    ];

    /** The country is the shop's own business: a coupon is serialised for the apps and the seller API exactly as it was. */
    protected $hidden = ['storefront_country'];

    protected $casts = [
        'id' => 'integer',
        'added_by' => 'string',
        'coupon_type' => 'string',
        'coupon_bearer' => 'string',
        'seller_id' => 'integer',
        'customer_id' => 'integer',
        'title' => 'string',
        'code' => 'string',
        'start_date' => 'datetime',
        'expire_date' => 'datetime',
        'min_purchase' => 'float',
        'max_discount' => 'float',
        'discount' => 'float',
        'discount_type' => 'string',
        'limit' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $businessMode = getWebConfig(name: 'business_mode');
        return $query->when($businessMode == 'single', function ($query) {
            $query->where(['added_by' => 'admin']);
        })->where(['status' => 1]);
    }

    /** The coupons the storefront may offer (GCC-05 slice 5): its own, and the default storefront also those with no country. Off by default. */
    public function scopeForStorefront(Builder $query, ?string $country = null): Builder
    {
        return CouponCountry::narrow(query: $query, country: $country);
    }

    public function order(): HasMany
    {
        return $this->hasMany(Order::class, 'coupon_code', 'code');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'shops');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'shops');
        });
    }
}
