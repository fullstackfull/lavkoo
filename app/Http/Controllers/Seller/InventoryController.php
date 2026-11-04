<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerCenter\Lists\InventoryList;
use App\Services\SellerCenter\TableFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Stock risk before it becomes a stockout, and the log that explains every change.
 *
 * The movement ledger is auditable and replay-free: every row carries the balance it left behind,
 * and the screen renders that figure rather than recomputing one (handoff 07.9).
 */
class InventoryController extends SellerCenterController
{
    public function __construct(private readonly InventoryList $inventory)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, $this->inventory->filterFields(), route('seller.inventory.index'));

        $products = $this->inventory->paginate($sellerId, $request);
        $productIds = $products->pluck('id')->all();

        return view('seller-views.inventory.index', [
            'products' => $products,
            'summary' => $this->inventory->summary($sellerId),
            'reserved' => $this->inventory->reservedFor($sellerId, $productIds),
            'velocity' => $this->inventory->velocityFor($sellerId, $productIds),
            'filters' => $filters,
            'list' => $this->inventory,
            'currentView' => $this->inventory->view($request),
            'state' => $this->listState($products->total(), $filters->isFiltered() || $request->query('view')),
        ]);
    }

    public function movements(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $movements = $this->inventory->movements($sellerId, $request);

        return view('seller-views.inventory.movements', [
            'movements' => $movements,
            'types' => $this->inventory->movementTypes(),
            'state' => $this->listState($movements->total(), $request->query('type') || $request->query('product_id')),
        ]);
    }
}
