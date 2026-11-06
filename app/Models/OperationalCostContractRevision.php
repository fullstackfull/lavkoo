<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a contract's change history: who did what, when, why, and what it looked like before and after. Never edited. */
class OperationalCostContractRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'operational_cost_contract_revisions';

    protected $guarded = [];

    protected $casts = [
        'contract_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
    ];
}
