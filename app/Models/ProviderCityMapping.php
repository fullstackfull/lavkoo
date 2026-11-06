<?php

namespace App\Models;

use App\Enums\Fulfillment\MappingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "For this provider, our city is that provider city." Provider identifiers live only here and are never sent to another
 * provider. A mapping routes an order only once it is approved, and at most one mapping per provider and city can be.
 */
class ProviderCityMapping extends Model
{
    public const APPROVED_SLOT = 1;

    protected $table = 'provider_city_mappings';

    protected $guarded = [];

    protected $casts = [
        'address_city_id' => 'integer',
        'status' => MappingStatus::class,
        'approved_slot' => 'integer',
        'evidence' => 'array',
        'verified_at' => 'datetime',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(AddressCity::class, 'address_city_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', MappingStatus::Approved->value);
    }

    public function isApproved(): bool
    {
        return $this->status === MappingStatus::Approved;
    }
}
