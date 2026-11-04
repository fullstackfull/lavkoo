<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational governance for one category (Phase 3, Stage A — spec item 10).
 */
class CategoryGovernance extends Model
{
    protected $table = 'category_governance';

    protected $fillable = [
        'category_id', 'return_window_days', 'required_attributes', 'tax_class',
        'requires_moderation', 'shipping_restricted', 'restriction_note', 'updated_by',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'return_window_days' => 'integer',
        'required_attributes' => 'array',
        'requires_moderation' => 'boolean',
        'shipping_restricted' => 'boolean',
    ];
}
