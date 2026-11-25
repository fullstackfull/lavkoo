<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A shipping rule copied towards another market and not yet published (H1-14).
 *
 * It is NOT a shipping method and must never be read as one: no storefront query knows this table, which is how "a copy does not affect the
 * live market" is kept by the schema rather than by everybody remembering a filter. The rule for turning one into a live method is
 * `App\Services\Storefront\ShippingRuleDrafts` and lives there.
 *
 * @property int $id
 * @property int|null $source_method_id
 * @property string $source_country
 * @property string $target_country
 * @property string|null $title
 * @property float $cost
 * @property string|null $duration
 * @property string|null $published_at
 * @property int|null $published_method_id
 */
class ShippingRuleDraft extends Model
{
    protected $table = 'shipping_rule_drafts';

    protected $fillable = [
        'source_method_id',
        'source_country',
        'target_country',
        'title',
        'cost',
        'duration',
        'copied_by',
        'copied_by_name',
        'published_by',
        'published_by_name',
        'published_at',
        'published_method_id',
    ];

    protected $casts = [
        'source_method_id' => 'integer',
        'cost' => 'float',
        'published_at' => 'datetime',
        'published_method_id' => 'integer',
    ];

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeForMarket(Builder $query, string $country): Builder
    {
        return $query->where('target_country', strtoupper(trim($country)));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
