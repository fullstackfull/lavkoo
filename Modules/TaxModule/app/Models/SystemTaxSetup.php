<?php

namespace Modules\TaxModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\TaxModule\app\Services\SystemTaxSetupService;

class SystemTaxSetup extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'integer',
        'is_active' => 'integer',
        'is_included' => 'integer',
        'tax_ids' => 'array',
    ];

    public function additionalData(): HasMany
    {
        return $this->hasMany(TaxAdditionalSetup::class, 'system_tax_setup_id');
    }

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
