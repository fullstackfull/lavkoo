<?php

namespace App\Models;

use App\Models\Seller;
use App\Models\Scopes\StaffCountryShippingScope;
use App\Services\Storefront\StorefrontShippingMethods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class ShippingMethod
 *
 * @property int $id
 * @property int|null $creator_id
 * @property string $creator_type
 * @property string|null $title
 * @property float $cost
 * @property string|null $duration
 * @property bool $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class ShippingMethod extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'creator_id',
        'creator_type',
        'title',
        'cost',
        'duration',
        'status',
        // The market this method belongs to (H1-14). Written only while storefronts are on AND the column
        // exists; empty on every method of a one-market platform, which is read as the default storefront's.
        'storefront_country',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status' => 'boolean',
        'cost' => 'float',
    ];

    public function seller():BelongsTo
    {
        return $this->belongsTo(Seller::class,'creator_id');
    }

    /**
     * The shipping rows an employee restricted to some countries may see (GCC-14c slice 6).
     *
     * Registered here rather than asked for at each call site, so a list, a counter, a picker or a lookup by id written anywhere is narrowed
     * the same way. It does nothing at all for a request no country was bound to, which is every request of the storefront side and every
     * request while the staff country scopes are off.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new StaffCountryShippingScope());
    }

    /**
     * Only the methods of a storefront (H1-14).
     *
     * The rule lives in `App\Services\Storefront\StorefrontShippingMethods`, which narrows nothing while storefronts
     * are off or before the migration has run, so a listing of a one-market platform is the listing it always was.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForStorefront(Builder $query, ?string $country = null): Builder
    {
        return app(StorefrontShippingMethods::class)->narrow($query, $country);
    }
}
