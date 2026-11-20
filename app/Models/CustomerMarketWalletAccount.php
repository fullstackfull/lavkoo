<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's wallet in one market (GCC-15, E-01): the money they hold in that market's currency, with the company that sells there.
 *
 * It is dormant until `gcc.storefronts.market_accounts.enabled` is on and the expand migration has run; while it is off the one balance on
 * the `users` row is the authority and no row of this table is read. Nothing but `App\Services\Wallet\CustomerMarketBalances` may change a
 * balance here, because only that service holds the row lock and the overdraft floor.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $market the two-letter country of the market, as `gcc.storefronts.countries` names it
 * @property string $currency_code
 * @property string|null $legal_entity
 * @property float $balance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CustomerMarketWalletAccount extends Model
{
    protected $fillable = [
        'customer_id',
        'market',
        'currency_code',
        'legal_entity',
        'balance',
    ];

    /**
     * An account answers with exactly the keys its own columns are. The sharing key E-02 leaves room for is hidden the way
     * `WalletTransaction` and `LoyaltyPointTransaction` hide what the E-05 ledger columns added, so a key a later slice begins to fill
     * cannot appear in an API answer the moment a migration runs, switch or no switch: `$hidden` is read by `toArray()` and `toJson()`,
     * which is how a model reaches an answer. Naming it here is not a read of it — it is the one declaration that says it is never served —
     * and `MarketAccountSharingKeyTest` holds both halves: the keys this model serialises, and the short list of files allowed to name the
     * column at all.
     */
    protected $hidden = [
        'sharing_group',
    ];

    protected $casts = [
        'id' => 'integer',
        'customer_id' => 'integer',
        'market' => 'string',
        'currency_code' => 'string',
        'legal_entity' => 'string',
        'balance' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
