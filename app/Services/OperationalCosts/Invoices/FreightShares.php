<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Models\OperationalCostInvoiceItem;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Collection;

/**
 * How the freight lines of a purchase invoice are shared over its goods lines: each freight line over ALL the goods lines, by value (equally when the goods are worth nothing, which is how `Money::allocate` splits).
 *
 * The share of a goods line that makes a layer of its own is added to that layer's landed cost (`landed`). The share of a goods line linked to a purchase order line is not: the order
 * makes that layer, and changing a layer's cost after the fact is not built. It is left with the freight line (`outsideLayers`), to be recorded as a cost of its own, and so is a
 * whole freight line when the invoice has no goods lines at all. Nothing of a freight line is dropped: what the layers carry and what is left add up to the line.
 */
final readonly class FreightShares
{
    /**
     * @param array<int, Money> $landed the freight each goods line that makes a layer of its own carries, by line id
     * @param array<int, Money> $outsideLayers the part of each freight line that no layer of this invoice carries, by freight line id
     */
    private function __construct(public array $landed, public array $outsideLayers)
    {
    }

    /**
     * @param Collection<int, OperationalCostInvoiceItem> $goods the goods lines, linked to an order line or not
     * @param Collection<int, OperationalCostInvoiceItem> $freight
     */
    public static function of(Collection $goods, Collection $freight): self
    {
        $goods = $goods->keyBy('id');
        $weights = $goods->map(static fn (OperationalCostInvoiceItem $item): Money => Money::of($item->amount))->all();

        $landed = [];
        $outsideLayers = [];
        foreach ($freight as $line) {
            $amount = Money::of($line->amount);
            if ($amount->isZero()) {
                continue;
            }
            if ($goods->isEmpty()) {
                $outsideLayers[$line->id] = $amount;
                continue;
            }
            foreach ($amount->allocate($weights) as $goodsId => $share) {
                if ($goods[$goodsId]->purchase_order_item_id === null) {
                    $landed[$goodsId] = ($landed[$goodsId] ?? Money::zero())->plus($share);
                } else {
                    $outsideLayers[$line->id] = ($outsideLayers[$line->id] ?? Money::zero())->plus($share);
                }
            }
        }

        return new self($landed, $outsideLayers);
    }
}
