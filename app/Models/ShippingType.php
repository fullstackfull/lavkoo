<?php

namespace App\Models;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class YourModel
 *
 * @property int $id
 * @property int|null $seller_id
 * @property string|null $shipping_type
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class ShippingType extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'seller_id',
        'shipping_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'seller_id' => 'integer',
        'shipping_type' => 'string',
    ];


    /** A storefront with its own currency prices shipping from the product alone (GCC-05): the stored type is the platform's, its tables are in the platform's money. */
    public function getShippingTypeAttribute(?string $value): ?string
    {
        return app(StorefrontCurrency::class)->shippingTypeInForce($value);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'shipping_types');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'shipping_types');
        });
    }
}
