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
        'created_at',
        'updated_at',
    ];

    /**
     * A customer's statement keeps exactly the keys it had. RestAPI/v1/UserWalletController::list() returns these models as they are, so a
     * column added to the ledger would otherwise appear in the customer app's answer the moment a migration ran, switch or no switch. The
     * list is the one gcc/p1-wallet-ledger-attribution (E-05) hides, so the two streams never fight over the shape.
     */
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
