<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listing on a sales channel, and (once a person or an explicit rule links it) the product of ours it stands for.
 *
 * A listing the application has read but nobody has linked keeps `product_id` NULL and status `unmapped` or `suggested`: reading a
 * channel never creates or changes a product. The channel's own identifiers live here, never on the product.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $product_id
 * @property string $listing_key
 * @property string $status
 */
class SalesChannelProductMapping extends Model
{
    use StoresLocalTime;

    public const UNMAPPED = 'unmapped';
    public const SUGGESTED = 'suggested';
    public const MAPPED = 'mapped';
    public const IGNORED = 'ignored';
    public const CONFLICT = 'conflict';

    protected $table = 'sales_channel_product_mappings';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'product_id' => 'integer',
        'suggested_product_id' => 'integer',
        'stock_location_id' => 'integer',
        'inventory_publish' => 'boolean',
        'price_publish' => 'boolean',
        'safety_buffer' => 'integer',
        'max_publish_quantity' => 'integer',
        'remote_price' => 'decimal:6',
        'remote_quantity' => 'integer',
        'last_published_price' => 'decimal:6',
        'last_published_quantity' => 'integer',
        'remote_updated_at' => 'datetime',
        'last_published_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'mapped_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SalesChannelAccount::class, 'account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isMapped(): bool
    {
        return $this->status === self::MAPPED && $this->product_id !== null;
    }

    public function scopeMapped(Builder $query): Builder
    {
        return $query->where('status', self::MAPPED)->whereNotNull('product_id');
    }

    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereIn('status', [self::UNMAPPED, self::SUGGESTED, self::CONFLICT]);
    }
}
