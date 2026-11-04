<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalised, searchable row per product per locale (Phase 2.1).
 *
 * Derived data: everything here is rebuilt from the product and its translations, so it is always
 * safe to delete and regenerate.
 */
class ProductSearchIndex extends Model
{
    protected $table = 'product_search_index';

    protected $fillable = ['product_id', 'locale', 'name_normalized', 'text_normalized'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
