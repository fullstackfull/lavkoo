<?php

namespace App\Models;

use App\Enums\OperationalCosts\ContractStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's price list for a period (Torod Point, M5AZN, a carrier, a payment gateway, a marketplace).
 *
 * A contract is edited while it is a draft. Once active it never changes: a new price list is a NEW VERSION (same `root_id`,
 * `version` + 1, its own start date) that takes over from its start date. Costs already computed keep pointing at the version
 * they were computed under, so changing a contract never rewrites history.
 *
 * @property int $id
 * @property int|null $root_id
 * @property int $version
 * @property string $provider_code
 * @property string $currency
 * @property ContractStatus $status
 */
class OperationalCostContract extends Model
{
    protected $table = 'operational_cost_contracts';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'root_id' => 'integer',
        'version' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'status' => ContractStatus::class,
        'settings' => 'array',
        'activated_at' => 'datetime',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(OperationalCostRule::class, 'contract_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OperationalCostContractRevision::class, 'contract_id')->orderBy('id');
    }

    /**
     * Was the contract in force on a given day? An active version covers its dates; a superseded one still covers the dates before
     * its successor started, which is what lets a cost for an old service be priced with the list that applied then.
     */
    public function coversDate(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return in_array($this->status, [ContractStatus::Active, ContractStatus::Superseded], true)
            && $this->effective_from->format('Y-m-d') <= $day
            && ($this->effective_to === null || $this->effective_to->format('Y-m-d') >= $day);
    }

    /** Contracts that can price a service: not drafts, not archived. */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereIn('status', [ContractStatus::Active->value, ContractStatus::Superseded->value]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Active->value);
    }
}
