<?php

namespace App\Models;

use App\Services\Storefront\StorefrontVisibility;
use App\Traits\HasStorefrontVisibility;
use App\Utils\Helpers;
use App\Utils\RequestArea;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;

/**
 * Class ProductDiscount
 *
 * @property int $id Primary
 * @property string $title
 * @property int $product_id
 * @property float $discount
 * @property string $discount_type
 * @property bool $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class DealOfTheDay extends Model
{
    use HasStorefrontVisibility;

    public const STOREFRONT_ENTITY_TYPE = StorefrontVisibility::DEAL_OF_THE_DAY;

    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'discount' => 'float',
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'title',
        'product_id',
        'discount',
        'discount_type',
        'status',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translationable');
    }

    public function getTitleAttribute($title): string|null
    {
        if (RequestArea::isPanel()) {
            return $title;
        }

        return $this->translations[0]->value??$title;
    }

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                if (RequestArea::isApi()){
                    return $query->where('locale', App::getLocale());
                }else{
                    return $query->where('locale', Helpers::default_lang());
                }
            }]);
        });
    }
}
