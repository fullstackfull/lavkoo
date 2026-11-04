<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One moderation decision on a product (Phase 3, Stage A — spec item 7).
 *
 * Append-only: the product's moderation history is the sequence of these, never edited.
 */
class ProductModerationEvent extends Model
{
    protected $fillable = [
        'product_id', 'action', 'reason_codes', 'note',
        'previous_request_status', 'new_request_status',
        'moderator_type', 'moderator_id', 'moderator_name',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'reason_codes' => 'array',
        'previous_request_status' => 'integer',
        'new_request_status' => 'integer',
    ];

    /** A product's moderation history, newest first. */
    public function scopeForProduct(Builder $query, int|string $productId): Builder
    {
        return $query->where('product_id', $productId)->latest('id');
    }
}
