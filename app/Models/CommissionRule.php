<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryProductRowScope;
use App\Services\Marketplace\CommissionEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One marketplace commission rule (Phase 3, Stage B).
 *
 * Scope and priority together decide which rule wins; see CommissionEngine for the ordering and
 * why it is stored rather than derived.
 */
class CommissionRule extends Model
{
    protected $fillable = [
        'scope_type', 'scope_id', 'rate_type', 'percentage', 'fixed_amount',
        'min_amount', 'max_amount', 'priority', 'is_active',
        'starts_at', 'ends_at', 'label', 'created_by',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'percentage' => 'float',
        'fixed_amount' => 'float',
        'min_amount' => 'float',
        'max_amount' => 'float',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Active *right now*: switched on, and inside its window if it has one. A rule with no dates is
     * always in window, which is what makes the common case (a permanent global rate) trivial.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * A commission rule that names a PRODUCT belongs to that product's market, so a restricted employee reads only their own countries'
     * (GCC-14c, slice 5). A global, category or vendor rule names no product and is left exactly as it was: it has no market to be hidden
     * by, and a restricted employee can see it without being able to write it (the rule of the screen refuses that). The engine that prices
     * a line is unaffected: it runs unbound on the storefront, and on a panel request the only product rule it can need is the one for the
     * product being sold, which is in the employee's own market. Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope(column: 'scope_id', onlyWhen: ['scope_type' => CommissionEngine::SCOPE_PRODUCT]));
    }
}
