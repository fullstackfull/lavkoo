<?php

namespace Modules\TaxModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;

class Taxable extends Model
{
    use HasFactory;

    protected $table = 'taxables';

    protected $guarded = ['id'];

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function taxable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            \Modules\TaxModule\app\Services\SystemTaxSetupService::clearTaxSystemTypeCache();
        });

        static::deleted(function ($model) {
            \Modules\TaxModule\app\Services\SystemTaxSetupService::clearTaxSystemTypeCache();
        });
    }
}
