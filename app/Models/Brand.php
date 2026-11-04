<?php

namespace App\Models;

use App\Traits\CacheManagerTrait;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\App;
use Modules\Auction\app\Models\AuctionProduct;

/**
 * @property int $id
 * @property string $name
 * @property string $image
 * @property int $status
 */
class Brand extends Model
{
    use StorageTrait, CacheManagerTrait;

    protected $fillable = [
        'name',
        'slug',
        'image',
        'mobile_image',
        'image_storage_type',
        'image_alt_text',
        'status'
    ];

    protected $casts = [
        'name' => 'string',
        'slug' => 'string',
        'image' => 'string',
        'mobile_image' => 'string',
        'image_storage_type' => 'string',
        'image_alt_text' => 'string',
        'status' => 'integer',
        'brand_products_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(): mixed
    {
        return $this->where('status', 1);
    }

    public function brandProducts(): HasMany
    {
        return $this->hasMany(Product::class)->active();
    }

    public function brandAllProducts(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function auctionProduct(): HasMany
    {
        return $this->hasMany(AuctionProduct::class, 'brand_id', 'id');
    }

    public function translations(): MorphMany
    {
        return $this->morphMany('App\Models\Translation', 'translationable');
    }

    public function seo(): MorphOne
    {
        return $this->morphOne('App\Models\SeoMetaInfo', 'seoable');
    }

    public function getNameAttribute($name): string|null
    {
        $segment = request()->segment(1);
        if ($segment === 'api') {
            return $this->translations[0]->value ?? $name;
        }
        if (in_array($segment, ['admin', 'vendor', 'seller'], true)) {
            return $name;
        }
        return $this->translations[0]->value ?? $name;
    }

    public function getDefaultNameAttribute(): string|null
    {
        return $this->translations[0]->value ?? $this->name;
    }

    public function storage(): MorphMany
    {
        return $this->morphMany(Storage::class, 'data');
    }

    public function getImageFullUrlAttribute(): array
    {
        $value = $this->image;
        return $this->storageLink('brand', $value, $this->image_storage_type ?? 'public');
    }

    /**
     * The phone-shaped logo, falling back to the web one.
     *
     * The fallback lives here rather than in each client: an app that had to decide for itself
     * would be one release away from showing nothing where a merchant never uploaded a second
     * image — which is almost every brand.
     */
    public function getMobileImageFullUrlAttribute(): array
    {
        return empty($this->mobile_image)
            ? $this->image_full_url
            : $this->storageLink('brand', $this->mobile_image, $this->image_storage_type ?? 'public');
    }

    protected $appends = ['image_full_url', 'mobile_image_full_url'];

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'brands');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'brands');
        });

        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                if (strpos(url()->current(), '/api')) {
                    return $query
                        ->where('locale', App::getLocale());
                } else {
                    return $query->where('locale', getDefaultLanguage());
                }
            }]);
        });
    }
}
