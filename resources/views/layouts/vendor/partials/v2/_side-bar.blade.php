@php
    use App\Enums\ViewPaths\Vendor\Profile;
    use App\Enums\ViewPaths\Vendor\Refund;
    use App\Enums\ViewPaths\Vendor\Review;
    use App\Models\Order;
    use App\Models\RefundRequest;
    $v2Seller = auth('seller')->user();
    $v2SellerId = $v2Seller['id'] ?? 0;
    $v2SellerPOS = getWebConfig('seller_pos');
    $v2HasPOS = ($v2SellerPOS == 1 && ($v2Seller['pos_status'] ?? 0) == 1);

    // Hide the entire Auction section when the global vendor-auction feature
    // is off AND this vendor has no historical auctions — the middleware
    // (AuctionVendorAccessMiddleware) blocks every vendor/auction route in
    // that case, so the menu would only lead to error redirects. Vendors
    // with prior auctions keep the menu so they can still see their history.
    $v2AuctionAddonPublished = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction');
    $v2AuctionFeatureEnabled = getWebConfig(name: 'auction_feature_status') == 1;
    $v2VendorHasAuctions = isset($sidebarAuctionVendorOwnCounts['all']) && $sidebarAuctionVendorOwnCounts['all'] > 0;
    $v2Auction = $v2AuctionAddonPublished && ($v2AuctionFeatureEnabled || $v2VendorHasAuctions);

    $v2Tax = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'TaxModule');
    $v2AuctionRoutes = $v2Auction ? include(base_path('Modules/Auction/Addon/vendor_routes.php')) : [];
    $v2TaxReportRoutes = $v2Tax ? include(base_path('Modules/TaxModule/Addon/vendor_tax_report_routes.php')) : [];

    // Pre-compute all order + refund counts once per render.
    $v2OrderBase = Order::where(['seller_is' => 'seller'])->where(['seller_id' => $v2SellerId]);
    $v2OrderCountAll        = (clone $v2OrderBase)->count();
    $v2OrderCountPending    = (clone $v2OrderBase)->where(['order_status' => 'pending'])->count();
    $v2OrderCountConfirmed  = (clone $v2OrderBase)->where(['order_status' => 'confirmed'])->count();
    $v2OrderCountProcessing = (clone $v2OrderBase)->where(['order_status' => 'processing'])->count();
    $v2OrderCountOut        = (clone $v2OrderBase)->where(['order_status' => 'out_for_delivery'])->count();
    $v2OrderCountDelivered  = (clone $v2OrderBase)->where(['order_status' => 'delivered'])->count();
    $v2OrderCountReturned   = (clone $v2OrderBase)->where(['order_status' => 'returned'])->count();
    $v2OrderCountFailed     = (clone $v2OrderBase)->where(['order_status' => 'failed'])->count();
    $v2OrderCountCanceled   = (clone $v2OrderBase)->where(['order_status' => 'canceled'])->count();

    $v2RefundBase = RefundRequest::whereHas('order', function ($q) use ($v2SellerId) {
        $q->where('seller_is', 'seller')->where('seller_id', $v2SellerId);
    });
    $v2RefundPending  = (clone $v2RefundBase)->where('status', 'pending')->count();
    $v2RefundApproved = (clone $v2RefundBase)->where('status', 'approved')->count();
    $v2RefundRefunded = (clone $v2RefundBase)->where('status', 'refunded')->count();
    $v2RefundRejected = (clone $v2RefundBase)->where('status', 'rejected')->count();
@endphp

<aside class="v2-ctxpanel" id="v2-ctxpanel">
    <div class="v2-ctx-scroll">

    {{-- ================= HOME (active by default) ================= --}}
    <div class="v2-ctx-section v2-is-on" data-section="home">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('home') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>
        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('overview') }}</span></div>
            <a class="v2-nav-item {{ Request::is('vendor/dashboard*') ? 'v2-is-active' : '' }}" data-item="v-dashboard" href="{{ route('vendor.dashboard.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('dashboard') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-dashboard" aria-label="Pin"></button></div>
            </a>
            @if ($v2HasPOS)
                <a class="v2-nav-item {{ Request::is('vendor/pos*') ? 'v2-is-active' : '' }}" data-item="v-pos" href="{{ route('vendor.pos.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('POS') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-pos" aria-label="Pin"></button></div>
                </a>
            @endif
        </div>

        @include('layouts.vendor.partials.v2._seller-center-links')
    </div>

    {{-- ================= ORDERS (Orders + Refund Requests) ================= --}}
    <div class="v2-ctx-section" data-section="orders">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('orders') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('sales') }}</span></div>

            <div class="v2-nav-item v2-has-children {{ Request::is('vendor/orders*') ? 'v2-is-active' : '' }}" data-item="v-orders">
                <a class="v2-nav-btn" href="{{ route('vendor.orders.list', ['all']) }}">
                    <span class="v2-nav-label">{{ translate('orders') }}</span>
                </a>
                <div class="v2-nav-right">
                    @if ($v2OrderCountPending > 0)
                        <span data-v2-tag="primary">{{ $v2OrderCountPending }}</span>
                    @endif
                    <button class="v2-pin-btn" type="button" data-pin="v-orders" aria-label="Pin"></button>
                    <span class="v2-nav-chev" aria-label="{{ translate('toggle') }}">
                        <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                    </span>
                </div>
            </div>
            <div class="v2-nav-children v2-is-collapsed" data-children-for="v-orders">
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/all') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['all']) }}">
                    <span class="v2-nav-child-dot" data-tone="primary"></span>
                    <span class="v2-nav-child-label">{{ translate('all') }}</span>
                    <span class="v2-nav-child-count" data-tone="primary">{{ $v2OrderCountAll }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/pending') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['pending']) }}">
                    <span class="v2-nav-child-dot" data-tone="primary"></span>
                    <span class="v2-nav-child-label">{{ translate('pending') }}</span>
                    <span class="v2-nav-child-count" data-tone="primary">{{ $v2OrderCountPending }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/confirmed') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['confirmed']) }}">
                    <span class="v2-nav-child-dot" data-tone="success"></span>
                    <span class="v2-nav-child-label">{{ translate('confirmed') }}</span>
                    <span class="v2-nav-child-count" data-tone="success">{{ $v2OrderCountConfirmed }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/processing') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['processing']) }}">
                    <span class="v2-nav-child-dot" data-tone="warn"></span>
                    <span class="v2-nav-child-label">{{ translate('packaging') }}</span>
                    <span class="v2-nav-child-count" data-tone="warn">{{ $v2OrderCountProcessing }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/out_for_delivery') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['out_for_delivery']) }}">
                    <span class="v2-nav-child-dot" data-tone="warn"></span>
                    <span class="v2-nav-child-label">{{ translate('out_for_delivery') }}</span>
                    <span class="v2-nav-child-count" data-tone="warn">{{ $v2OrderCountOut }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/delivered') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['delivered']) }}">
                    <span class="v2-nav-child-dot" data-tone="success"></span>
                    <span class="v2-nav-child-label">{{ translate('delivered') }}</span>
                    <span class="v2-nav-child-count" data-tone="success">{{ $v2OrderCountDelivered }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/returned') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['returned']) }}">
                    <span class="v2-nav-child-dot" data-tone="danger"></span>
                    <span class="v2-nav-child-label">{{ translate('returned') }}</span>
                    <span class="v2-nav-child-count" data-tone="danger">{{ $v2OrderCountReturned }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/failed') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['failed']) }}">
                    <span class="v2-nav-child-dot" data-tone="danger"></span>
                    <span class="v2-nav-child-label">{{ translate('failed To Deliver') }}</span>
                    <span class="v2-nav-child-count" data-tone="danger">{{ $v2OrderCountFailed }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/orders/list/canceled') ? 'v2-is-on' : '' }}" href="{{ route('vendor.orders.list', ['canceled']) }}">
                    <span class="v2-nav-child-dot" data-tone="neutral"></span>
                    <span class="v2-nav-child-label">{{ translate('canceled') }}</span>
                    <span class="v2-nav-child-count" data-tone="danger">{{ $v2OrderCountCanceled }}</span>
                </a>
            </div>

            <div class="v2-nav-item v2-has-children {{ Request::is('vendor/refund*') ? 'v2-is-active' : '' }}" data-item="v-refunds">
                <a class="v2-nav-btn" href="{{ route('vendor.refund.index', ['pending']) }}">
                    <span class="v2-nav-label">{{ translate('refund_Requests') }}</span>
                </a>
                <div class="v2-nav-right">
                    @if ($v2RefundPending > 0)
                        <span data-v2-tag="warn">{{ $v2RefundPending }}</span>
                    @endif
                    <button class="v2-pin-btn" type="button" data-pin="v-refunds" aria-label="Pin"></button>
                    <span class="v2-nav-chev">
                        <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                    </span>
                </div>
            </div>
            <div class="v2-nav-children v2-is-collapsed" data-children-for="v-refunds">
                <a class="v2-nav-child {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/pending') ? 'v2-is-on' : '' }}" href="{{ route('vendor.refund.index', ['pending']) }}">
                    <span class="v2-nav-child-dot" data-tone="primary"></span>
                    <span class="v2-nav-child-label">{{ translate('pending') }}</span>
                    <span class="v2-nav-child-count" data-tone="primary">{{ $v2RefundPending }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/approved') ? 'v2-is-on' : '' }}" href="{{ route('vendor.refund.index', ['approved']) }}">
                    <span class="v2-nav-child-dot" data-tone="success"></span>
                    <span class="v2-nav-child-label">{{ translate('approved') }}</span>
                    <span class="v2-nav-child-count" data-tone="success">{{ $v2RefundApproved }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/refunded') ? 'v2-is-on' : '' }}" href="{{ route('vendor.refund.index', ['refunded']) }}">
                    <span class="v2-nav-child-dot" data-tone="success"></span>
                    <span class="v2-nav-child-label">{{ translate('refunded') }}</span>
                    <span class="v2-nav-child-count" data-tone="success">{{ $v2RefundRefunded }}</span>
                </a>
                <a class="v2-nav-child {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/rejected') ? 'v2-is-on' : '' }}" href="{{ route('vendor.refund.index', ['rejected']) }}">
                    <span class="v2-nav-child-dot" data-tone="danger"></span>
                    <span class="v2-nav-child-label">{{ translate('rejected') }}</span>
                    <span class="v2-nav-child-count" data-tone="danger">{{ $v2RefundRejected }}</span>
                </a>
            </div>
        </div>
    </div>

    <div class="v2-ctx-section" data-section="products">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('catalog') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('products') }}</span></div>

            <a class="v2-nav-item {{ (Request::is('vendor/products/list/all') || Request::is('vendor/products/update*') || Request::is('vendor/products/view*')) ? 'v2-is-active' : '' }}" data-item="v-products" href="{{ route('vendor.products.list', ['type' => 'all']) }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_list') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-products" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/list/approved') ? 'v2-is-active' : '' }}" data-item="v-products-approved" href="{{ route('vendor.products.list', ['type' => 'approved']) }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('approved_product_list') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-products-approved" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/list/new-request') ? 'v2-is-active' : '' }}" data-item="v-products-new" href="{{ route('vendor.products.list', ['type' => 'new-request']) }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('new_product_request') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-products-new" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/list/denied') ? 'v2-is-active' : '' }}" data-item="v-products-denied" href="{{ route('vendor.products.list', ['type' => 'denied']) }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('denied_product_request') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-products-denied" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ (Request::is('vendor/products/add') || (Request::is('vendor/products/update/*') && request()->has('product-gallery'))) ? 'v2-is-active' : '' }}" data-item="v-products-add" href="{{ route('vendor.products.add') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('add_new_product') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-products-add" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/product-gallery') ? 'v2-is-active' : '' }}" data-item="v-product-gallery" href="{{ route('vendor.products.product-gallery') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_gallery') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-product-gallery" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/stock-limit-list') ? 'v2-is-active' : '' }}" data-item="v-limited-stock" href="{{ route('vendor.products.stock-limit-list') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Limited_Stocks') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-limited-stock" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/bulk-import') ? 'v2-is-active' : '' }}" data-item="v-bulk-import" href="{{ route('vendor.products.bulk-import') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('bulk_import') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-bulk-import" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/products/request-restock-list') ? 'v2-is-active' : '' }}" data-item="v-restock" href="{{ route('vendor.products.request-restock-list') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Request_Restock_List') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-restock" aria-label="Pin"></button></div>
            </a>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('product_Reviews') }}</span></div>
            <a class="v2-nav-item {{ Request::is('vendor/reviews/' . Review::INDEX[URI] . '*') ? 'v2-is-active' : '' }}" data-item="v-reviews" href="{{ route('vendor.reviews.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_Reviews') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-reviews" aria-label="Pin"></button></div>
            </a>
        </div>
    </div>

    @if ($v2Auction)
        <div class="v2-ctx-section" data-section="auction">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('Auction_Management') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            @foreach ($v2AuctionRoutes as $v2AuctionGroup)
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate($v2AuctionGroup['name']) }}</span></div>
                    @foreach ($v2AuctionGroup['routes'] as $v2AuctionRoute)
                        @if(($v2AuctionRoute['path'] != 'vendor/auction/products/add') || (getWebConfig(name: 'auction_feature_status') == 1 && getWebConfig(name: 'active_auction_for_vendor') == 1))
                        @php
                            $badgeData = function_exists('getAuctionSidebarBadgeData')
                                ? getAuctionSidebarBadgeData($v2AuctionRoute['path'], $sidebarAuctionVendorOwnCounts ?? [])
                                : ['is_status_route' => false, 'badge_class' => 'neutral', 'count' => 0];
                            $v2Slug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($v2AuctionGroup['name'] . '-' . $v2AuctionRoute['name']));
                            $v2Tone = match($badgeData['badge_class'] ?? 'neutral') {
                                'success' => 'success',
                                'danger' => 'danger',
                                'warning' => 'warn',
                                'info' => 'info',
                                default => 'primary',
                            };
                            // Detail/edit/update/recreate auction pages have no list-path match,
                            // so fall back to highlighting "All Auction" for those URLs.
                            $v2IsAllAuctionFallback = $v2AuctionRoute['path'] === 'vendor/auction/products/list/all'
                                && (Request::is('vendor/auction/products/view*')
                                    || Request::is('vendor/auction/products/edit*')
                                    || Request::is('vendor/auction/products/update/*')
                                    || Request::is('vendor/auction/products/recreate/*'));
                            $v2AuctionIsActive = strstr(Request::url(), $v2AuctionRoute['path']) || $v2IsAllAuctionFallback;
                        @endphp
                        <a class="v2-nav-item {{ $v2AuctionIsActive ? 'v2-is-active' : '' }}" data-item="v-auction-{{ $v2Slug }}" href="{{ $v2AuctionRoute['url'] }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($v2AuctionRoute['name']) }}</span></span>
                            <div class="v2-nav-right">
                                @if ($badgeData['is_status_route'])
                                    <span data-v2-tag="{{ $v2Tone }}">{{ $badgeData['count'] }}</span>
                                @endif
                                <button class="v2-pin-btn" type="button" data-pin="v-auction-{{ $v2Slug }}" aria-label="Pin"></button>
                            </div>
                        </a>
                        @endif
                    @endforeach
                </div>
            @endforeach

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('Finance') }}</span></div>
                <a class="v2-nav-item {{ Request::is('vendor/auction/auction-withdraw*') ? 'v2-is-active' : '' }}" data-item="auction-withdraw" href="{{ route('vendor.auction.auction-withdraw') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Withdraw_List') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="auction-withdraw" aria-label="Pin"></button></div>
                </a>
            </div>
        </div>
    @endif

    {{-- ================= PROMOTIONS (Coupons + Clearance Sale) ================= --}}
    <div class="v2-ctx-section" data-section="marketing">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('marketing') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>
        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('promotion_management') }}</span></div>
            <a class="v2-nav-item {{ Request::is('vendor/coupon*') ? 'v2-is-active' : '' }}" data-item="v-coupons" href="{{ route('vendor.coupon.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('coupons') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-coupons" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/clearance-sale*') ? 'v2-is-active' : '' }}" data-item="v-clearance" href="{{ route('vendor.clearance-sale.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Clearance_Sale') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-clearance" aria-label="Pin"></button></div>
            </a>
        </div>
    </div>

    {{-- ================= REPORTS ================= --}}
    <div class="v2-ctx-section" data-section="reports">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('reports') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>
        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('reports_&_analytics') }}</span></div>
            <a class="v2-nav-item {{ (Request::is('vendor/transaction/order-list') || Request::is('vendor/transaction/expense-list') || Request::is('vendor/transaction/order-history-log*')) ? 'v2-is-active' : '' }}" data-item="v-transactions" href="{{ route('vendor.transaction.order-list') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('transactions_Report') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-transactions" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ (Request::is('vendor/report/all-product') || Request::is('vendor/report/stock-product-report')) ? 'v2-is-active' : '' }}" data-item="v-product-report" href="{{ route('vendor.report.all-product') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_report') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-product-report" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/report/order-report') ? 'v2-is-active' : '' }}" data-item="v-order-report" href="{{ route('vendor.report.order-report') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('order_Report') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-order-report" aria-label="Pin"></button></div>
            </a>

            @if ($v2Tax)
                @foreach ($v2TaxReportRoutes as $v2TaxRoute)
                    @php
                        $v2TaxActive = (bool) strstr(Request::url(), $v2TaxRoute['path']);
                        if (!$v2TaxActive && !empty($v2TaxRoute['sub_routes'])) {
                            foreach ($v2TaxRoute['sub_routes'] as $v2TaxSub) {
                                if (strstr(Request::url(), $v2TaxSub['path'])) { $v2TaxActive = true; break; }
                            }
                        }
                        $v2TaxSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($v2TaxRoute['name']));
                    @endphp
                    <a class="v2-nav-item {{ $v2TaxActive ? 'v2-is-active' : '' }}" data-item="v-tax-{{ $v2TaxSlug }}" href="{{ $v2TaxRoute['url'] }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($v2TaxRoute['name']) }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-tax-{{ $v2TaxSlug }}" aria-label="Pin"></button></div>
                    </a>
                @endforeach
            @endif
        </div>
        @if ($v2Auction)
            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('auction_Report') }}</span></div>
                <a class="v2-nav-item {{ Request::is('vendor/auction/reports/sales') ? 'v2-is-active' : '' }}" data-item="v-auction-sales-report" href="{{ route('vendor.auction.reports.sales') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Auction_Sales_Report') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-auction-sales-report" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('vendor/auction/reports/vat-report*') ? 'v2-is-active' : '' }}" data-item="v-auction-vat-report" href="{{ route('vendor.auction.reports.vat-report') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Auction_VAT_Report') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-auction-vat-report" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('vendor/auction/reports/transaction-history*') ? 'v2-is-active' : '' }}" data-item="v-auction-transaction-history" href="{{ route('vendor.auction.reports.transaction-history') }}">
                    <span class="v2-nav-btn">
                        <span class="v2-nav-label">
                            {{ translate('Transaction with Admin') }}
                        </span>
                    </span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-auction-transaction-history" aria-label="Pin"></button></div>
                </a>
            </div>
        @endif
    </div>

    {{-- ================= SETTINGS (Shipping, Withdraws, Bank, Shop) ================= --}}
    <div class="v2-ctx-section" data-section="settings">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('settings') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>
        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('business_section') }}</span></div>
            <a class="v2-nav-item {{ Request::is('vendor/business-settings/shipping-method*') ? 'v2-is-active' : '' }}" data-item="v-shipping" href="{{ route('vendor.business-settings.shipping-method.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('shipping_methods') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-shipping" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/business-settings/withdraw*') ? 'v2-is-active' : '' }}" data-item="v-withdraws" href="{{ route('vendor.business-settings.withdraw.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('withdraws') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-withdraws" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ (Request::is('vendor/profile/' . Profile::INDEX[URI]) || Request::is('vendor/profile/' . Profile::BANK_INFO_UPDATE[URI])) ? 'v2-is-active' : '' }}" data-item="v-bank" href="{{ route('vendor.profile.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('bank_Information') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-bank" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/shop*') ? 'v2-is-active' : '' }}" data-item="v-shop" href="{{ route('vendor.shop.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('shop_Settings') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-shop" aria-label="Pin"></button></div>
            </a>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('marketplace') }}</span></div>
            @if (Route::has('vendor.business-settings.seller-center.index'))
                <a class="v2-nav-item {{ Request::is('vendor/business-settings/seller-center*') ? 'v2-is-active' : '' }}" data-item="v-seller-center" href="{{ route('vendor.business-settings.seller-center.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('seller_center') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-seller-center" aria-label="Pin"></button></div>
                </a>
            @endif
            @if (Route::has('vendor.business-settings.staff.index'))
                <a class="v2-nav-item {{ Request::is('vendor/business-settings/staff*') ? 'v2-is-active' : '' }}" data-item="v-staff-roles" href="{{ route('vendor.business-settings.staff.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('staff_and_roles') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-staff-roles" aria-label="Pin"></button></div>
                </a>
            @endif
            @if (Route::has('vendor.business-settings.payouts.index'))
                <a class="v2-nav-item {{ Request::is('vendor/business-settings/payouts*') ? 'v2-is-active' : '' }}" data-item="v-payouts" href="{{ route('vendor.business-settings.payouts.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('payouts') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-payouts" aria-label="Pin"></button></div>
                </a>
            @endif
            @if (Route::has('vendor.business-settings.seller-verification.index'))
                <a class="v2-nav-item {{ Request::is('vendor/business-settings/seller-verification*') ? 'v2-is-active' : '' }}" data-item="v-verification" href="{{ route('vendor.business-settings.seller-verification.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('verification') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-verification" aria-label="Pin"></button></div>
                </a>
            @endif
            @if (Route::has('vendor.business-settings.seller-scorecard.index'))
                <a class="v2-nav-item {{ Request::is('vendor/business-settings/seller-scorecard*') ? 'v2-is-active' : '' }}" data-item="v-performance" href="{{ route('vendor.business-settings.seller-scorecard.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('performance') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-performance" aria-label="Pin"></button></div>
                </a>
            @endif
        </div>
    </div>

    {{-- ================= PEOPLE (Delivery Man + Support) — merged section
         matching the admin v2 sidebar pattern: a single "people" rail entry
         whose ctxpanel groups together everything human-facing (deliverymen
         and customer-support inbox). ================= --}}
    <div class="v2-ctx-section" data-section="people">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('people') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('deliveryman') }}</span></div>

            <a class="v2-nav-item {{ Request::is('vendor/delivery-man/index') ? 'v2-is-active' : '' }}" data-item="v-dm-add" href="{{ route('vendor.delivery-man.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('add_new') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-dm-add" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ (Request::is('vendor/delivery-man/list') || Request::is('vendor/delivery-man/update') || Request::is('vendor/delivery-man/rating/*') || Request::is('vendor/delivery-man/wallet*')) ? 'v2-is-active' : '' }}" data-item="v-delivery-man" href="{{ route('vendor.delivery-man.list') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('delivery-Man') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-delivery-man" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/delivery-man/withdraw/*') ? 'v2-is-active' : '' }}" data-item="v-dm-withdraw" href="{{ route('vendor.delivery-man.withdraw.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('withdraws') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-dm-withdraw" aria-label="Pin"></button></div>
            </a>
            <a class="v2-nav-item {{ Request::is('vendor/delivery-man/emergency-contact/*') ? 'v2-is-active' : '' }}" data-item="v-dm-emergency" href="{{ route('vendor.delivery-man.emergency-contact.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('emergency_contact') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-dm-emergency" aria-label="Pin"></button></div>
            </a>
        </div>

        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('support') }}</span></div>
            <a class="v2-nav-item {{ Request::is('vendor/messages*') ? 'v2-is-active' : '' }}" data-item="v-inbox" href="{{ route('vendor.messages.index', ['type' => 'customer']) }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('inbox') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="v-inbox" aria-label="Pin"></button></div>
            </a>
        </div>
    </div>

    </div>{{-- /.v2-ctx-scroll --}}

    {{-- Setup guide footer (pinned at bottom when ctxpanel is visible) --}}
    @php
        $v2Setup = function_exists('checkSetupGuideRequirements') ? checkSetupGuideRequirements(panel: 'vendor') : ['completePercent' => 100, 'totalSteps' => 0];
        $v2ShowSetupGuide = ($v2Setup['completePercent'] ?? 100) < 100;
    @endphp
    @if ($v2ShowSetupGuide)
        <div class="v2-ctx-foot">
            <button type="button" class="v2-setup-guide" data-v2-setup-trigger aria-label="{{ translate('Setup_Guide') }}">
                <div class="v2-setup-guide-icon">
                    <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/setup_guide.png') }}"
                         width="18" height="18" alt="">
                </div>
                <div class="v2-setup-guide-body">
                    <span class="v2-setup-guide-title">{{ translate('Setup_Guide') }}</span>
                    <span class="v2-setup-guide-sub">{{ $v2Setup['completePercent'] ?? 0 }}% {{ translate('complete') }}</span>
                </div>
                <div class="v2-setup-guide-chev">
                    <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                </div>
            </button>
        </div>
    @endif

</aside>

<aside class="v2-rail-flyout" id="v2-rail-flyout" aria-hidden="true"></aside>
