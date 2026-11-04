<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A destination shipping zone with its rate rule (Phase 3, Stage C).
 */
class ShippingZone extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name', 'countries', 'regions', 'base_cost', 'per_kg_cost', 'free_over', 'priority', 'status', 'created_by',
    ];

    protected $casts = [
        'countries' => 'array',
        'regions' => 'array',
        'base_cost' => 'decimal:2',
        'per_kg_cost' => 'decimal:2',
        'free_over' => 'decimal:2',
        'priority' => 'integer',
        'created_by' => 'integer',
    ];

    /** Does this zone serve the given country? An empty country list is a catch-all (rest of world). */
    public function matchesCountry(?string $country): bool
    {
        $countries = $this->countries ?? [];
        if ($countries === []) {
            return true;    // catch-all
        }
        if ($country === null || $country === '') {
            return false;
        }
        $needle = mb_strtolower(trim($country));

        foreach ($countries as $c) {
            if (mb_strtolower(trim((string) $c)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
