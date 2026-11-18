<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class WalletTransaction
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $transaction_id
 * @property string|null $reference
 * @property string|null $transaction_type
 * @property float $credit
 * @property float $debit
 * @property float $admin_bonus
 * @property float $balance
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $storefront_country
 * @property string|null $currency_code
 * @property int|null $legal_entity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'reference',
        'transaction_type',
        'credit',
        'debit',
        'admin_bonus',
        'balance',
        'payment_method',
        'source_type',
        'source_id',
        'actor_type',
        'actor_id',
        'storefront_country',
        'currency_code',
        'legal_entity_id',
        'created_at',
        'updated_at',
    ];

    /** What stands behind a row (App\Services\Storefront\LedgerAttribution) is internal: a customer's statement keeps the keys it had. */
    protected $hidden = [
        'source_type',
        'source_id',
        'actor_type',
        'actor_id',
        'storefront_country',
        'currency_code',
        'legal_entity_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'credit' => 'float',
        'debit' => 'float',
        'admin_bonus' => 'float',
        'balance' => 'float',
        'reference' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
