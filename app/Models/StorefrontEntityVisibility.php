<?php

namespace App\Models;

use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * One mapping row: this entity, this storefront, and what the row means (hidden or assigned). It carries no copy of the entity, so the
 * central row stays the single place a category, a brand, a banner, a coupon or a campaign is defined.
 *
 * @property string $entity_type
 * @property int $entity_id
 * @property string $storefront_country
 * @property string $mode
 */
class StorefrontEntityVisibility extends Model
{
    protected $table = 'storefront_entity_visibility';

    protected $fillable = ['entity_type', 'entity_id', 'storefront_country', 'mode'];

    protected $casts = [
        'entity_type' => 'string',
        'entity_id' => 'integer',
        'storefront_country' => 'string',
        'mode' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The mapping decides whether a reader narrows at all, so a row written through this model — by the service, a seeder or a test —
     * makes the readers look again instead of answering from what the process learnt before the row existed.
     *
     * These events are the model's, not the table's: a row inserted straight onto the table with `DB::table()->insert()` fires nothing,
     * and the readers keep the answer they learnt for up to {@see StorefrontVisibility} ANSWER_SECONDS. Write through this model, or
     * through {@see StorefrontVisibility::set()}, or call {@see StorefrontVisibility::flush()} yourself.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saved(fn () => StorefrontVisibility::flush());
        static::deleted(fn () => StorefrontVisibility::flush());
    }
}
