<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A named, reusable answer to "which products" (Phase 3.1).
 *
 * A section's source used to be one of six fixed orderings. A collection is a merchant-defined
 * seventh: rules chosen from an allowlist, ranked by a precomputed metric, referenced by any
 * number of sections through the settings they already have. Deleting or disabling one never
 * breaks a page — the section falls back to its catalogue source.
 */
class ProductCollection extends Model
{
    protected $fillable = ['name', 'slug', 'status', 'rules', 'sort_by', 'merchandising'];

    protected $casts = [
        'status'        => 'boolean',
        'rules'         => 'array',
        'merchandising' => 'array',
    ];

    public function scopeLive($query)
    {
        return $query->where('status', true);
    }

    /** @return array<int, array<string, mixed>> always a list, whatever the column holds */
    public function ruleRows(): array
    {
        return array_values(array_filter(
            is_array($this->rules) ? $this->rules : [],
            'is_array',
        ));
    }

    public static function slugFor(string $name): string
    {
        return Str::slug(Str::limit(trim($name), 56, ''));
    }
}
