<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $mapping_id
 * @property string $external_line_id
 * @property int $quantity
 */
class ExternalOrderLine extends Model
{
    use StoresLocalTime;

    protected $table = 'external_order_lines';

    protected $guarded = [];

    protected $casts = [
        'mapping_id' => 'integer',
        'quantity' => 'integer',
        'quantity_cancelled' => 'integer',
        'quantity_returned' => 'integer',
        'unit_price' => 'decimal:6',
        'line_total' => 'decimal:6',
        'discount_total' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'shipping_total' => 'decimal:6',
        'refunded_total' => 'decimal:6',
        'refunded_tax' => 'decimal:6',
        'weight_kg' => 'decimal:3',
        'product_id' => 'integer',
        'inventory_location_id' => 'integer',
        'snapshot' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderMapping::class, 'mapping_id');
    }

    /** @return HasMany<OperationalCostReturnDecision, $this> */
    public function returnDecisions(): HasMany
    {
        return $this->hasMany(OperationalCostReturnDecision::class, 'target_id')->where('target_type', OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE);
    }

    /**
     * Lines with returned units that nobody has decided about: more units came back (the channel accepted them) than the decisions recorded for the line account for.
     *
     * @param Builder<ExternalOrderLine> $query
     */
    public function scopeAwaitingReturnDecision(Builder $query): void
    {
        $query->where('quantity_returned', '>', 0)->whereRaw(
            'quantity_returned > coalesce((select sum(units_restocked + units_written_off) from operational_cost_return_decisions as decisions'
            . ' where decisions.target_type = ? and decisions.target_id = external_order_lines.id), 0)',
            [OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE],
        );
    }

    /** Units still to be fulfilled: ordered, less cancelled. Returned units were fulfilled first, so they are not subtracted. */
    public function activeQuantity(): int
    {
        return max(0, $this->quantity - $this->quantity_cancelled);
    }
}
