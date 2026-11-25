<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryProductRowScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a product sits in one warehouse (Phase 3, Stage C).
 */
class WarehouseStock extends Model
{
    protected $table = 'warehouse_stock';

    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * A shelf balance is of one product, so a restricted employee reads only the balances of their own countries' products -- which is as far as the staff scope reaches on these screens, because the WAREHOUSE itself carries no market (GCC-14c, slice 5; G1-05).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope());
    }
}
