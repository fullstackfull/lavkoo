<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payment gateway routing rule (Phase 3, Stage E).
 */
class PaymentRoutingRule extends Model
{
    public const ACTION_HIDE = 'hide';
    public const ACTION_PREFER = 'prefer';
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'name', 'gateway_code', 'action', 'min_amount', 'max_amount', 'country', 'priority', 'status', 'created_by',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'priority' => 'integer',
        'created_by' => 'integer',
    ];

    /** Does this rule's conditions match the given order context? */
    public function matches(float $amount, ?string $country): bool
    {
        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }
        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            return false;
        }
        if ($this->country !== null && $this->country !== '' && mb_strtolower(trim($this->country)) !== mb_strtolower(trim((string) $country))) {
            return false;
        }

        return true;
    }
}
