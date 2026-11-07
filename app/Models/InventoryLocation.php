<?php

namespace App\Models;

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
}
