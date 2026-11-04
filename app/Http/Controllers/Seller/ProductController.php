<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerCenter\Lists\ProductList;
use App\Services\SellerCenter\TableFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Catalogue management with the listing problem, not just the listing (handoff 07.7).
 *
 * The Issue column always carries the precise reason a product is not selling. If the server sends
 * no reason the cell shows the status only — the word "Error" on its own is a defect.
 */
class ProductController extends SellerCenterController
{
    public function __construct(private readonly ProductList $products)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, $this->products->filterFields(), route('seller.products.index'));

        $products = $this->products->paginate($sellerId, $request);
        $issues = $this->products->issuesFor($sellerId, $products->pluck('id')->all());

        return view('seller-views.products.index', [
            'products' => $products,
            'issues' => $issues,
            'filters' => $filters,
            'list' => $this->products,
            'currentView' => $this->products->view($request),
            'state' => $this->listState($products->total(), $filters->isFiltered() || $request->query('view')),
        ]);
    }
}
