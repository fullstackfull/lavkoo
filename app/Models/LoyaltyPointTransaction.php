<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class LoyaltyPointTransaction
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $transaction_id
 * @property float $credit
 * @property float $debit
 * @property float $balance
 * @property string|null $reference
 * @property string|null $transaction_type
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $storefront_country
 * @property int|null $legal_entity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */

class LoyaltyPointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'credit',
        'debit',
        'balance',
        'reference',
        'transaction_type',
        'source_type',
        'source_id',
        'actor_type',
        'actor_id',
        'storefront_country',
        'legal_entity_id',
    ];

    /** What stands behind a row (App\Services\Storefront\LedgerAttribution) is internal: a customer's statement keeps the keys it had. */
    protected $hidden = [
        'source_type',
        'source_id',
        'actor_type',
        'actor_id',
        'storefront_country',
        'legal_entity_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'credit' => 'float',
        'debit' => 'float',
        'balance'=>'float',
        'reference'=>'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
