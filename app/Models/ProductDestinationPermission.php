<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission for one product to reach one destination country (H1-03): carrying it, taking it out of where it is sold, bringing it into
 * where it is going. Absence is a refusal, not a gap -- {@see \App\Services\Shipping\ProductDestinationPermission} is what reads it.
 *
 * @property int $product_id
 * @property string $destination_country
 * @property bool $may_ship
 * @property bool $may_export
 * @property bool $may_import
 */
class ProductDestinationPermission extends Model
{
    protected $table = 'product_destination_permissions';

    protected $fillable = ['product_id', 'destination_country', 'may_ship', 'may_export', 'may_import', 'recorded_by', 'recorded_at'];

    protected $casts = [
        'may_ship' => 'boolean',
        'may_export' => 'boolean',
        'may_import' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
