<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded change to a currency's exchange rate (Phase 3, Stage E).
 */
class ExchangeRateLog extends Model
{
    protected $fillable = ['currency_id', 'code', 'old_rate', 'new_rate', 'source', 'changed_by'];

    protected $casts = [
        'currency_id' => 'integer',
        'old_rate' => 'decimal:6',
        'new_rate' => 'decimal:6',
        'changed_by' => 'integer',
    ];
}
