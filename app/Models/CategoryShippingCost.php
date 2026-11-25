<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\Storefront\StorefrontCategoryShippingCosts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class SellerProduct
 *
 * @property int $id Primary
 * @property int $seller_id
 * @property int $category_id
 * @property float $cost
 * @property bool $multiply_qty
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class CategoryShippingCost extends Model
{
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'seller_id' => 'integer',
        'category_id' => 'integer',
        'cost' => 'float',
        'multiply_qty' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'seller_id',
        'category_id',
        'cost',
        'multiply_qty',
        // The market this cost was typed for (H1-14); empty on every cost of a one-market platform.
        'storefront_country',
    ];

    public function category():BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Only the category costs of a storefront (H1-14).
     *
     * The rule lives in `App\Services\Storefront\StorefrontCategoryShippingCosts`, which narrows nothing while
     * storefronts are off or before the migration has run, so a basket of a one-market platform is priced by the same
     * query it always was.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForStorefront(Builder $query, ?string $country = null): Builder
    {
        return app(StorefrontCategoryShippingCosts::class)->narrow($query, $country);
    }
}
