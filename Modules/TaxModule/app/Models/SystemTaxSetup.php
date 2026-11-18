<?php

namespace Modules\TaxModule\app\Models;

use App\Services\Storefront\StorefrontTax;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The rows no storefront owns, which is the platform's setup. A row that carries the code of a configured storefront other than the default one belongs to that
     * storefront (GCC-05 and D1-07), so the screens and services that mean "the" setup of the platform leave it out; a row with no code, or any other code, stays.
     */
    public function scopeOfThePlatform(Builder $query): Builder
    {
        $storefronts = app(StorefrontTax::class)->storefrontCountries();

        return $query->where(static fn (Builder $inner): Builder => $inner->whereNull('country_code')->orWhereNotIn('country_code', $storefronts));
    }

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
