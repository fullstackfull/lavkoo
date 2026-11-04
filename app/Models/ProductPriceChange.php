<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded price change.
 *
 * Kept as its own record rather than a generic audit line because a price has a shape the generic
 * trail cannot query: "what was this product's price on the fourteenth" is a lookup, not a scan of
 * every action anyone took.
 */
class ProductPriceChange extends Model
{
    public const SOURCE_SELLER_UI = 'seller_ui';
    public const SOURCE_ADMIN_UI = 'admin_ui';
    public const SOURCE_API = 'api';
    public const SOURCE_BULK_JOB = 'bulk_job';
    public const SOURCE_PROMOTION = 'promotion';
    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_IMPORT = 'import';

    public const SOURCES = [
        self::SOURCE_SELLER_UI, self::SOURCE_ADMIN_UI, self::SOURCE_API,
        self::SOURCE_BULK_JOB, self::SOURCE_PROMOTION, self::SOURCE_AUTOMATION, self::SOURCE_IMPORT,
    ];

    protected $fillable = [
        'product_id', 'seller_id',
        'previous_price', 'new_price',
        'previous_discount', 'new_discount', 'previous_discount_type', 'new_discount_type',
        'source', 'reason', 'actor_type', 'actor_id', 'actor_name',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'seller_id' => 'integer',
        'previous_price' => 'float',
        'new_price' => 'float',
        'previous_discount' => 'float',
        'new_discount' => 'float',
        'actor_id' => 'integer',
    ];

    /** A first listing rather than a change: there was no price before this one. */
    public function isFirstPrice(): bool
    {
        return $this->previous_price === null;
    }

    /** Signed, so a list can be read for what moved rather than by comparing two columns. */
    public function delta(): float
    {
        return round($this->new_price - (float) ($this->previous_price ?? $this->new_price), 3);
    }
}
