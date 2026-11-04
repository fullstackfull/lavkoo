<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One shop's floor under its own prices.
 *
 * Both limits are optional and a shop may set either, both or neither. Neither set is not the same
 * as not enforcing: a policy switched on with nothing in it computes no floor and refuses nothing,
 * which is the honest outcome rather than a floor of zero.
 */
class SellerPricingPolicy extends Model
{
    protected $fillable = [
        'seller_id',
        'updated_by_staff_id',
        'min_margin_percent',
        'min_price',
        'enforce',
    ];

    protected $casts = [
        'min_margin_percent' => 'float',
        'min_price' => 'float',
        'enforce' => 'boolean',
    ];

    public function isBinding(): bool
    {
        return $this->enforce && ($this->min_margin_percent !== null || $this->min_price !== null);
    }
}
