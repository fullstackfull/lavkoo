<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Services\OperationalCosts\Returns\ReturnDecisionService;
use App\Utils\Helpers;
use Illuminate\Support\Collection;

/**
 * What the screens of one channel order show about its returned units: for each line that has any (or can have one recorded), the claims nobody decided about, the decisions made,
 * and the places the units may be put back. The same panel is on the order page of the sales channels and on the profit page of the sale; whoever lacks the operational costs
 * permission sees the figures and no form (the route refuses them anyway).
 */
class ReturnPanel
{
    /** The states in which goods may have come back: an order that has not shipped has nothing to return. */
    private const RETURNABLE = [ChannelOrderStatus::Shipped, ChannelOrderStatus::Delivered, ChannelOrderStatus::Returned];

    public function __construct(private readonly ReturnDecisionService $decisions)
    {
    }

    /**
     * @return array{items: list<array{line: ExternalOrderLine, standing: array<string, mixed>}>, locations: Collection<int, InventoryLocation>, canDecide: bool}
     */
    public function forOrder(ExternalOrderMapping $mapping): array
    {
        $returnable = in_array($mapping->orderStatus(), self::RETURNABLE, true);
        $items = [];
        foreach ($mapping->lines()->orderBy('id')->get() as $line) {
            $line->setRelation('order', $mapping);
            $standing = $this->decisions->standing($line);
            if ($line->quantity_returned > 0 || $standing['decisions']->isNotEmpty() || ($returnable && $standing['canRecordAReturn'])) {
                $items[] = ['line' => $line, 'standing' => $standing];
            }
        }

        return [
            'items' => $items,
            'locations' => InventoryLocation::query()->active()->where('seller_id', $mapping->seller_id)->orderBy('name')->get(['id', 'name', 'seller_id']),
            'canDecide' => Helpers::module_permission_check('operational_costs'),
        ];
    }
}
