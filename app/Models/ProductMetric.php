<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One product's precomputed engagement numbers, rebuilt by `commerce:metrics-refresh`.
 *
 * Collections rank and filter against this row so a Home request never aggregates the order or
 * analytics tables. Every column comes from data the platform already records — order_details,
 * the analytics product rollups, reviews and wish lists; nothing here is fabricated.
 */
class ProductMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'sales_30d', 'views_30d', 'carted_30d', 'rating', 'wishlist_count', 'computed_at',
    ];

    protected $casts = [
        'product_id'     => 'integer',
        'sales_30d'      => 'integer',
        'views_30d'      => 'integer',
        'carted_30d'     => 'integer',
        'rating'         => 'float',
        'wishlist_count' => 'integer',
        'computed_at'    => 'datetime',
    ];
}
