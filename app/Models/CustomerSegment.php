<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A rule-based reading of who a customer is to the shop (Phase 3.4).
 *
 * "Repeat buyer" is orders_count >= 2, not a list somebody maintains: membership is computed
 * from the shop's own records at request time, so it is always current and there is no list to
 * go stale. The key is what sections target, beside guest/customer in the audience tokens.
 */
class CustomerSegment extends Model
{
    protected $fillable = ['name', 'key', 'status', 'rules'];

    protected $casts = [
        'status' => 'boolean',
        'rules'  => 'array',
    ];

    public function scopeLive($query)
    {
        return $query->where('status', true);
    }

    /** @return array<int, array<string, mixed>> */
    public function ruleRows(): array
    {
        return array_values(array_filter(
            is_array($this->rules) ? $this->rules : [],
            'is_array',
        ));
    }

    public static function keyFor(string $name): string
    {
        return Str::slug(Str::limit(trim($name), 56, ''));
    }
}
