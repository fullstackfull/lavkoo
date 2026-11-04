<?php

namespace App\Models;

use App\Models\Builders\AuditedBuilder;
use App\Traits\CacheManagerTrait;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    use CacheManagerTrait;

    protected $fillable = ['type', 'value', 'created_at', 'updated_at'];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Settings are written with mass updates, which raise no model event. The audited builder is
     * what makes a change to a gateway key or a mail password leave a line without every one of the
     * ~100 call sites having to remember to write one.
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'business_settings');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'business_settings');
        });
    }
}
