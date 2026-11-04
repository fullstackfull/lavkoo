<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A frozen commission snapshot for one order line (Phase 3, Stage B).
 *
 * Written once at order time and never recalculated. Settlements and statements read this rather
 * than re-deriving from live rules, so editing a rule tomorrow cannot restate yesterday's accounts.
 */
class OrderItemCommission extends Model
{
    protected $fillable = [
        'order_id', 'order_details_id', 'product_id', 'seller_id', 'seller_is',
        'commission_rule_id', 'rule_scope_type', 'rule_label',
        'rate_type', 'percentage', 'fixed_amount',
        'commissionable_amount', 'commission_amount', 'seller_net_amount',
        'currency', 'reversed_amount',
    ];

    protected $casts = [
        'percentage' => 'float',
        'fixed_amount' => 'float',
        'commissionable_amount' => 'float',
        'commission_amount' => 'float',
        'seller_net_amount' => 'float',
        'reversed_amount' => 'float',
    ];

    /** Commission actually owed after any refund reversal. */
    public function getNetCommissionAttribute(): float
    {
        return round($this->commission_amount - $this->reversed_amount, 4);
    }
}
