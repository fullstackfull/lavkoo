<?php

namespace App\Models;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One cost, as the ledger holds it. Everything the cost and profit modules report is a sum over these rows.
 *
 *  - `amount` is NULL exactly when `state` is `missing`: a cost that could not be determined is not a zero.
 *  - Of all rows that share a `slot_key` (the same service for the same subject) only one is `is_effective`: when the real
 *    charge arrives it supersedes the estimate, so a service is never counted twice.
 *  - A row that has been approved or reconciled is never recomputed. Its figures cannot change, and a mistake is corrected with a
 *    new entry that supersedes or reverses it, so the history stays what it was when it was approved.
 *  - Rows are never deleted.
 *
 * @property int $id
 * @property string $dedupe_key
 * @property string $slot_key
 * @property CostState $state
 * @property string|null $amount
 * @property string $currency
 * @property bool $is_effective
 */
class OperationalCostEntry extends Model
{
    use StoresLocalTime;

    /** Columns that describe the cost itself; frozen once the entry is approved or reconciled. */
    public const FROZEN_WHEN_APPROVED = [
        'dedupe_key', 'slot_key', 'subject_type', 'subject_id', 'cost_component', 'cost_class', 'amount', 'currency', 'fx_rate',
        'base_amount', 'base_currency', 'quantity', 'unit_amount', 'source', 'contract_id', 'contract_version', 'rule_id',
        'calc_inputs', 'period_date', 'occurred_at',
    ];

    protected $table = 'operational_cost_entries';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'subject_id' => 'integer',
        'account_id' => 'integer',
        'order_id' => 'integer',
        'external_order_mapping_id' => 'integer',
        'provider_fulfillment_id' => 'integer',
        'inventory_location_id' => 'integer',
        'product_id' => 'integer',
        'state' => CostState::class,
        'is_effective' => 'boolean',
        'superseded_by_id' => 'integer',
        'amount' => 'decimal:6',
        'fx_rate' => 'decimal:10',
        'base_amount' => 'decimal:6',
        'quantity' => 'decimal:6',
        'unit_amount' => 'decimal:6',
        'contract_id' => 'integer',
        'contract_version' => 'integer',
        'rule_id' => 'integer',
        'invoice_item_id' => 'integer',
        'calc_inputs' => 'array',
        'period_date' => 'date',
        'occurred_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $entry): void {
            $wasSettled = $entry->getOriginal('approved_at') !== null || $entry->getOriginal('state') === CostState::Reconciled;
            if (!$wasSettled) {
                return;
            }
            foreach (self::FROZEN_WHEN_APPROVED as $attribute) {
                if ($entry->isDirty($attribute)) {
                    throw new LogicException("An approved cost entry is never recomputed: '{$attribute}' cannot change. Add a correcting entry instead.");
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Cost entries are never deleted: supersede or reverse them.');
        });
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OperationalCostAllocation::class, 'entry_id');
    }

    public function component(): ?CostComponent
    {
        return CostComponent::tryFrom($this->cost_component);
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('is_effective', true);
    }

    /** Costs somebody still has to settle: a charge that is contested, or a newer figure that was kept beside an approved one and not yet decided. */
    public function scopeOpenDisputes(Builder $query): Builder
    {
        return $query->inState(CostState::Disputed)->where(static fn (Builder $open): Builder => $open->where('is_effective', true)->orWhereNull('superseded_by_id'));
    }

    public function scopeInState(Builder $query, CostState ...$states): Builder
    {
        return $query->whereIn('state', array_map(static fn (CostState $state): string => $state->value, $states));
    }
}
