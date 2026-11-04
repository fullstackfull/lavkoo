<?php

namespace App\Http\Controllers\Seller;

use App\Models\Order;
use App\Services\Marketplace\SellerOrderBreakdownService;
use App\Services\Marketplace\SellerOrderTimelineService;
use App\Services\SellerCenter\Lists\OrderList;
use App\Services\SellerCenter\TableFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The order queue and one order's detail.
 *
 * Ready to ship, Shipped, Delivered and Cancelled are saved views of this one list, not separate
 * screens with their own tables (handoff 01 §6) — the same query, the same columns, one set of
 * filters in the URL.
 */
class OrderController extends SellerCenterController
{
    public function __construct(
        private readonly OrderList $orders,
        private readonly SellerOrderTimelineService $timeline,
        private readonly SellerOrderBreakdownService $breakdown,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, $this->orders->filterFields(), route('seller.orders.index'));

        $orders = $this->orders->paginate($sellerId, $request);

        return view('seller-views.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'views' => $this->orders->views($sellerId, $request, route('seller.orders.index')),
            'currentView' => (string) $request->query('view', 'all'),
            'list' => $this->orders,
            'state' => $this->listState($orders->total(), $filters->isFiltered() || $request->query('view')),
        ]);
    }

    public function show(Request $request, int $orderId): View
    {
        $principal = $this->principal($request);

        $order = Order::with(['customer', 'shipping', 'deliveryMan', 'orderDetails.product'])
            ->where(['seller_is' => 'seller', 'seller_id' => $principal->sellerId()])
            ->find($orderId);

        // Another shop's order is not found, not forbidden: an id must not be a way to learn that
        // an order exists somewhere else.
        abort_if($order === null, 404);

        return view('seller-views.orders.show', [
            'order' => $order,
            'timeline' => $this->timeline->timelineFor($orderId, $principal->sellerId()),
            // The earnings block requires finance read; without it the card is absent rather than
            // blanked, and the order total stays visible (handoff 07.6).
            'breakdown' => $principal->can('finance.view')
                ? $this->breakdown->breakdownFor($orderId, $principal->sellerId())
                : null,
            'canSeeEarnings' => $principal->can('finance.view'),
            'sla' => $this->orders->slaFor($order),
        ]);
    }
}
