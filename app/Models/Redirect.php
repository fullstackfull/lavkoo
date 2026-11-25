<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SEO redirect rule (301/302). Part of the centralized SEO subsystem (Phase 1.6).
 *
 * @property int    $id
 * @property string $from_path
 * @property string $to_path
 * @property int    $status_code
 * @property string $match_type
 * @property bool   $is_active
 * @property int    $hits
 * @property string|null $storefront_country the market the rule applies in; null is the default storefront's, which is every rule today
 */
class Redirect extends Model
{
    /** Cache key for the active-rules set shared by the storefront middleware and admin CRUD. */
    public const ACTIVE_CACHE_KEY = 'seo_active_redirects';

    protected $fillable = [
        'from_path',
        'to_path',
        'status_code',
        'match_type',
        'is_active',
        'hits',
        'last_hit_at',
        'note',
        'created_by_type',
        'created_by_id',
        // The market the rule applies in (A1-V2). Nothing in the admin screens sets it yet, so every rule a person writes is still the
        // default storefront's; it is fillable so that whatever sets it next does not have to force it past the model.
        'storefront_country',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'is_active'   => 'boolean',
        'hits'        => 'integer',
        'last_hit_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
