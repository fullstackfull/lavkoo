<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryProductRowScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a purchase order (Phase 3, Stage C).
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'description', 'sku',
        'qty_ordered', 'qty_received', 'unit_cost', 'line_total',
    ];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'product_id' => 'integer',
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** How many units of this line are still to be received. */
    public function outstanding(): int
    {
        return max(0, (int) $this->qty_ordered - (int) $this->qty_received);
    }

    /**
     * A purchase-order line names at most one product, and a line that names none buys something that is not in the catalogue. A restricted employee reads only the lines of their own countries' products (GCC-14c, slice 5); a line with no product belongs to no market and is not one of theirs.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope());
    }
}
