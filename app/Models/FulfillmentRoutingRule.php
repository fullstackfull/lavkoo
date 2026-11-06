<?php

namespace App\Models;

use App\Enums\SalesChannels\RoutingAction;
use Illuminate\Database\Eloquent\Model;

/** One administrator-managed rule of the DynamicFulfillmentRoutingEngine: what it matches, and what it decides. */
class FulfillmentRoutingRule extends Model
{
    protected $table = 'fulfillment_routing_rules';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'account_id' => 'integer',
        'product_id' => 'integer',
        'inventory_location_id' => 'integer',
        'target_location_id' => 'integer',
        'requires_confirmed_stock' => 'boolean',
        'conditions' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];

    public function routingAction(): ?RoutingAction
    {
        return RoutingAction::tryFrom((string) $this->action);
    }

    /** How many conditions the rule sets: the tie-break between rules of equal priority is "more specific wins". */
    public function specificity(): int
    {
        return collect([
            'sales_channel', 'account_id', 'fulfillment_mode', 'execution_policy', 'product_id', 'sku',
            'inventory_location_id', 'product_provider', 'country', 'city',
        ])->filter(fn (string $column): bool => $this->{$column} !== null && $this->{$column} !== '')->count()
            + ($this->requires_confirmed_stock ? 1 : 0);
    }
}
