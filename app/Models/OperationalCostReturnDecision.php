<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What was decided about the units of a channel order line that came back, once per line and return event: some put back on the shelf at their original cost
 * (`units_restocked`, with the cost layer and the ledger entry that did it), some written off as damaged (`units_written_off`, their cost of goods stays in the profit).
 *
 * @property int $id
 * @property int $target_id
 * @property string $return_event
 * @property int $units_restocked
 * @property int $units_written_off
 */
class OperationalCostReturnDecision extends Model
{
    public const TARGET_CHANNEL_ORDER_LINE = 'channel_order_line';

    /** The width of the `return_event` column: an event that does not fit is refused, never truncated into another one's key. */
    public const RETURN_EVENT_LENGTH = 120;

    protected $table = 'operational_cost_return_decisions';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'target_id' => 'integer',
        'units_restocked' => 'integer',
        'units_written_off' => 'integer',
        'inventory_location_id' => 'integer',
        'layer_id' => 'integer',
        'entry_id' => 'integer',
        'automatic' => 'boolean',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function units(): int
    {
        return $this->units_restocked + $this->units_written_off;
    }
}
