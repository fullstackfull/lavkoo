<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryMarketRowScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place stock lives, with its own balances and its own source of truth. Torod Point's warehouse, M5AZN's supplier stock and
 * Amazon's fulfilment centres are three locations; a product can have a balance in several of them and they never merge.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property string|null $provider
 * @property string $source_of_truth
 * @property bool $storefront_sellable
 * @property array|null $publishable_channels
 * @property string|null $storefront_country the market the warehouse is in; null is the default storefront's (G1-05)
 * @property int|null $legal_entity_id the selling company whose stock it holds; null while no company has been named
 */
class InventoryLocation extends Model
{
    public const TYPE_TOROD = 'torod_warehouse';
    public const TYPE_M5AZN = 'm5azn_supplier';
    public const TYPE_AMAZON_FBA = 'amazon_fba';
    public const TYPE_OWN = 'own_warehouse';
    public const TYPE_OTHER = 'other';

    public const TRUTH_PROVIDER = 'provider';
    public const TRUTH_CHANNEL = 'channel';
    public const TRUTH_MANUAL = 'manual';
    /** The storefront's own stock (`products.current_stock`) is the reference: the quantity is read through, never copied. */
    public const TRUTH_PLATFORM = 'platform';

    protected $table = 'inventory_locations';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'sales_channel_account_id' => 'integer',
        'storefront_sellable' => 'boolean',
        'publishable_channels' => 'array',
        'is_active' => 'boolean',
        'legal_entity_id' => 'integer',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryLocationBalance::class, 'location_id');
    }

    public function isChannelOwned(): bool
    {
        return $this->source_of_truth === self::TRUTH_CHANNEL;
    }

    /** The stock here is the storefront's own: the store takes units off it at order placement, and channel units never leave it. */
    public function isStorefrontOwned(): bool
    {
        return $this->source_of_truth === self::TRUTH_PLATFORM;
    }

    public function mayPublishTo(string $channelKey): bool
    {
        return $this->is_active && in_array($channelKey, $this->publishable_channels ?? [], true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * A warehouse carries its own market (`storefront_country`, G1-05), so a restricted employee reads only the shelves of their countries
     * (GCC-14c, slices 5 and 6) -- and an unmarked location is the default storefront's, which is the rule `StorefrontMarketRows` owns.
     * Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryMarketRowScope());
    }
}
