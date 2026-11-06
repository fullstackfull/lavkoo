<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A routing decision, kept with the rule that produced it, the facts it was based on and the reason in words. Never edited after it is applied. */
class FulfillmentRoutingDecision extends Model
{
    protected $table = 'fulfillment_routing_decisions';

    protected $guarded = [];

    protected $casts = [
        'external_order_mapping_id' => 'integer',
        'order_id' => 'integer',
        'account_id' => 'integer',
        'rule_id' => 'integer',
        'rule_snapshot' => 'array',
        'inputs' => 'array',
        'line_decisions' => 'array',
        'version' => 'integer',
        'is_current' => 'boolean',
        'applied' => 'boolean',
        'applied_at' => 'datetime',
    ];
}
