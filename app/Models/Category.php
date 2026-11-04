<?php

namespace App\Models;

use App\Traits\CacheManagerTrait;
use App\Traits\StorageTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\App;
use Modules\Auction\app\Models\AuctionProduct;
use Modules\TaxModule\app\Models\Taxable;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $icon
 * @property int $parent_id
 * @property int $position
 * @property int $home_status
 * @property int $auction_home_status
 * @property int $priority
 */
class Category extends Model
{
    use StorageTrait, CacheManagerTrait;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'mobile_icon',
        'icon_storage_type',
        'parent_id',
        'position',
        'home_status',
        'auction_home_status',
        'priority',
    ];

    protected $casts = [
        'name' => 'string',
        'slug' => 'string',
        'icon' => 'string',
        'mobile_icon' => 'string',
        'icon_storage_type' => 'string',
        'parent_id' => 'integer',
        'position' => 'integer',
        'home_status' => 'integer',
        'auction_home_status' => 'integer',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function translations(): MorphMany
    {
        return $this->morphMany('App\Models\Translation', 'translationable');
    }

    public function seo(): MorphOne
    {
        return $this->morphOne('App\Models\SeoMetaInfo', 'seoable');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id')->orderBy('priority', 'desc');
    }

    public function childes(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('priority', 'asc');
    }

    public function product(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'id');
    }

    public function auctionProduct(): HasMany
    {
        return $this->hasMany(AuctionProduct::class, 'category_id', 'id');
    }

    // Old Relation: sub_category_product
    public function subCategoryProduct(): HasMany
    {
        return $this->hasMany(Product::class, 'sub_category_id', 'id');
    }

    // Old Relation: sub_sub_category_product
    public function subSubCategoryProduct(): HasMany
    {
        return $this->hasMany(Product::class, 'sub_sub_category_id', 'id');
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


    public function scopePriority($query): mixed
    {
        return $query->orderBy('priority', 'asc');
    }

    public function taxVats(): MorphMany
    {
        return $this->morphMany(Taxable::class, 'taxable')
            ->where('tax_for', 'tax_for_orders');
    }

    public function getIconFullUrlAttribute():array
    {
        $value = $this->icon;
        return $this->storageLink('category',$value,$this->icon_storage_type ?? 'public');
    }

    /**
     * The phone-shaped icon, falling back to the web one.
     *
     * The fallback lives here rather than in each client: an app that had to decide for itself
     * would be one release away from showing nothing where a merchant never uploaded a second
     * image — which is almost every category.
     */
    public function getMobileIconFullUrlAttribute(): array
    {
        return empty($this->mobile_icon)
            ? $this->icon_full_url
            : $this->storageLink('category', $this->mobile_icon, $this->icon_storage_type ?? 'public');
    }

    protected $appends = ['icon_full_url', 'mobile_icon_full_url'];

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'categories');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'categories');
        });

        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                if (strpos(url()->current(), '/api')) {
                    return $query->where('locale', App::getLocale());
                } else {
                    return $query->where('locale', getDefaultLanguage());
                }
            }]);
        });
    }
}
