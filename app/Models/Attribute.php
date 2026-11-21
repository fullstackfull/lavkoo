<?php

namespace App\Models;

use App\Services\Storefront\StorefrontVisibility;
use App\Traits\HasStorefrontVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;

/**
 * A variation attribute — Size, Colour, Voltage — and the fifth entity type the storefront mapping narrows (B-12).
 *
 * It is `assigned`: an attribute with no mapping row is offered everywhere, which is exactly what every attribute does today, and one
 * with any row is offered only in the storefronts its rows name. The attribute carries no country of its own, so the central row is
 * never touched and removing the mapping restores it in every market.
 *
 * @property int $id
 * @property string $name
 */
class Attribute extends Model
{
    use HasStorefrontVisibility;

    public const STOREFRONT_ENTITY_TYPE = StorefrontVisibility::ATTRIBUTE;

    protected $fillable = [
        'name',
    ];

    protected $casts = [
        'id' => 'integer',
        'name' => 'string'
    ];

    public function translations(): MorphMany
    {
        return $this->morphMany('App\Models\Translation', 'translationable');
    }

    protected static function boot(): void
    {
        parent::boot();
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
