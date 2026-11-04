<?php

namespace Modules\TaxModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\TaxModule\app\Services\SystemTaxSetupService;

class Tax extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'integer',
        'is_active' => 'integer',
        'tax_rate' => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            SystemTaxSetupService::clearTaxSystemTypeCache();
        });

        static::deleted(function ($model) {
            SystemTaxSetupService::clearTaxSystemTypeCache();
        });
    }

}
