<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\StaffCountryProductRowScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class YourModel
 *
 * @property int $id
 * @property int $flash_deal_id
 * @property int $product_id
 * @property float $discount
 * @property string $discount_type
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class FlashDealProduct extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'flash_deal_id',
        'product_id',
        'discount',
        'discount_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'discount' => 'decimal:2',
    ];

    /**
     * The products of a deal are narrowed to the countries of a restricted employee (GCC-14c, slice 4), so a deal's product list and the
     * count the deal list prints beside it hold only the products that employee may see. Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function flashDeal(): BelongsTo
    {
        return $this->belongsTo(FlashDeal::class)->where(['deal_type' => 'flash_deal', 'status' => 1]);
    }

    public function featureDeal(): BelongsTo
    {
        return $this->belongsTo(FlashDeal::class, 'flash_deal_id', 'id')->where(['deal_type' => 'feature_deal', 'status' => 1]);
    }
}
