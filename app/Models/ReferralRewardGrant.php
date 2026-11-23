<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $referee_id
 * @property int $beneficiary_id
 * @property int $order_id
 * @property int|null $wallet_transaction_id
 */
class ReferralRewardGrant extends Model
{
    protected $fillable = [
        'referee_id',
        'beneficiary_id',
        'order_id',
        'wallet_transaction_id',
    ];

    protected $casts = [
        'referee_id' => 'integer',
        'beneficiary_id' => 'integer',
        'order_id' => 'integer',
        'wallet_transaction_id' => 'integer',
    ];
}
