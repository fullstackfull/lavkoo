<?php

namespace App\Models;

use App\Enums\OperationalCosts\CalcMethod;
use App\Enums\OperationalCosts\ContractStatus;
use App\Enums\OperationalCosts\CostComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One price on a contract: a service, how it is calculated, the figures, and the conditions under which it applies.
 *
 * The rules of a contract that is no longer a draft cannot be changed or removed: that is what keeps the figures a historical
 * cost was computed with readable forever.
 *
 * @property int $id
 * @property int $contract_id
 * @property string $service_type
 * @property string $calc_method
 * @property array $params
 * @property array|null $conditions
 */
class OperationalCostRule extends Model
{
    protected $table = 'operational_cost_rules';

    protected $guarded = [];

    protected $casts = [
        'contract_id' => 'integer',
        'params' => 'array',
        'conditions' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $rule): void {
            $status = OperationalCostContract::whereKey($rule->contract_id)->first()?->status;
            if ($status !== null && $status !== ContractStatus::Draft) {
                throw new LogicException('The rules of a contract that is not a draft cannot change: create a new version of the contract.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OperationalCostContract::class, 'contract_id');
    }

    public function component(): ?CostComponent
    {
        return CostComponent::tryFrom($this->service_type);
    }

    public function method(): ?CalcMethod
    {
        return CalcMethod::tryFrom($this->calc_method);
    }
}
