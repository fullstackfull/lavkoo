<?php

namespace App\Models;

use App\Traits\CacheManagerTrait;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Class YourModel
 *
 * @property int $id
 * @property int $seller_id
 * @property string $author_type
 * @property string $name
 * @property string $address
 * @property string $contact
 * @property string $image
 * @property string|null $bottom_banner
 * @property string|null $offer_banner
 * @property string|null $vacation_duration_type
 * @property string|null $vacation_start_date
 * @property string|null $vacation_end_date
 * @property string|null $vacation_note
 * @property bool $vacation_status
 * @property bool $temporary_close
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $banner
 * @property string|null $banner_storage_type
 * @property string|null $setup_guide
 * @property string|null $setup_guide_app
 * @property string|null $tax_identification_number
 * @property Carbon|null $tin_expire_date
 * @property string|null $tin_certificate
 * @property string|null $tin_certificate_storage_type
 *
 * @package App\Models
 */
class Shop extends Model
{
    use StorageTrait, CacheManagerTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'seller_id',
        'author_type',
        'name',
        'slug',
        'address',
        'contact',
        'image',
        'image_storage_type',
        'bottom_banner',
        'bottom_banner_storage_type',
        'offer_banner',
        'offer_banner_storage_type',
        'vacation_duration_type',
        'vacation_start_date',
        'vacation_end_date',
        'vacation_note',
        'vacation_status',
        'temporary_close',
        'banner',
        'banner_storage_type',
        'setup_guide',
        'setup_guide_app',
        'tax_identification_number',
        'tin_expire_date',
        'tin_certificate',
        'tin_certificate_storage_type',
    ];

    protected $appends = ['image_full_url', 'bottom_banner_full_url', 'offer_banner_full_url', 'banner_full_url', 'tin_certificate_full_url'];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'seller_id' => 'integer',
        'author_type' => 'string',
        'vacation_status' => 'boolean',
        'temporary_close' => 'boolean',
        'setup_guide' => 'array',
        'setup_guide_app' => 'array',
        'tin_expire_date' => 'date',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    // old relation: product
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'user_id', 'seller_id')->where(['added_by' => 'seller', 'status' => 1, 'request_status' => 1]);
    }

    public function scopeActive($query)
    {
        return $query->whereHas('seller', function ($query) {
            $query->where(['status' => 'approved']);
        });
    }

    public function getImageFullUrlAttribute(): string|null|array
    {
        $value = $this->image;
        return $this->storageLink('shop', $value, $this->image_storage_type ?? 'public');
    }

    public function getBannerFullUrlAttribute(): string|null|array
    {
        $value = $this->banner;
        return $this->storageLink('shop/banner', $value, $this->banner_storage_type ?? 'public');
    }

    public function getBottomBannerFullUrlAttribute(): string|null|array
    {
        $value = $this->bottom_banner;
        return $this->storageLink('shop/banner', $value, $this->bottom_banner_storage_type ?? 'public');
    }

    public function getOfferBannerFullUrlAttribute(): string|null|array
    {
        $value = $this->offer_banner;
        return $this->storageLink('shop/banner', $value, $this->offer_banner_storage_type ?? 'public');
    }

    public function getTinCertificateFullUrlAttribute(): string|null|array
    {
        $value = $this->tin_certificate;
        return $this->storageLink('shop/documents', $value, $this->tin_certificate_storage_type ?? 'public');
    }

    public function scopeApplyNameFilter($query, $shopName)
    {
        // searchTerms(), not explode(): `?shop_name[]=x` is truthy and explode() rejects an array,
        // which made the public store list a 500 for anyone who sent one.
        $keywords = searchTerms($shopName);

        if ($keywords === []) return $query;

        return $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $word) {
                $q->orWhere('name', 'like', "%{$word}%");
            }
        });
    }



    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'shops');
            cacheRemoveByType(type: 'in_house_shop');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'shops');
            cacheRemoveByType(type: 'in_house_shop');
        });
    }
}
