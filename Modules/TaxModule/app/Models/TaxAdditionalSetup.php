<?php

namespace Modules\TaxModule\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\TaxModule\app\Services\SystemTaxSetupService;

class TaxAdditionalSetup extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'integer',
        'is_active' => 'integer',
        'is_included' => 'integer',
        'system_tax_setup_id' => 'integer',
        'tax_ids' => 'array',
    ];

    public function systemTaxVat(): BelongsTo
    {
        return $this->belongsTo(SystemTaxSetup::class);
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
