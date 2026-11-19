<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's loyalty points in one market (GCC-15, E-02): the points they hold there, and the currency those points convert into in
 * that market, so a market's points are never exchanged at another market's rate.
 *
 * It is dormant until `gcc.storefronts.market_accounts.enabled` is on and the expand migration has run; while it is off the one point
 * balance on the `users` row is the authority and no row of this table is read. Nothing but
 * `App\Services\Wallet\CustomerMarketBalances` may change a balance here.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $market the two-letter country of the market, as `gcc.storefronts.countries` names it
 * @property string $currency_code the currency this market's points convert into
 * @property string|null $legal_entity
 * @property float $points
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CustomerMarketLoyaltyAccount extends Model
{
    protected $fillable = [
        'customer_id',
        'market',
        'currency_code',
        'legal_entity',
        'points',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'market' => 'string',
        'currency_code' => 'string',
        'legal_entity' => 'string',
        'points' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
