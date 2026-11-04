@php
    use App\Utils\Helpers;
    use App\Enums\EmailTemplateKey;
    $v2Auction = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction');
    $v2Tax = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'TaxModule');
    $v2Ai = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'AI');
    $v2AuctionRoutes = $v2Auction ? include(base_path("Modules/Auction/Addon/admin_routes.php")) : [];
    $v2TaxRoutes = $v2Tax ? include(base_path("Modules/TaxModule/Addon/tax_routes.php")) : [];
    $v2TaxReportRoutes = $v2Tax ? include(base_path("Modules/TaxModule/Addon/tax_report_routes.php")) : [];

    $v2AuctionReportRoutes = $v2Auction ? include(base_path("Modules/Auction/Addon/auction_report_routes.php")) : [];

    $v2ThemeRoutes = config('get_theme_routes') ?: [];
    $v2AddonAdminRoutes = config('addon_admin_routes') ?: [];
@endphp

<aside class="v2-ctxpanel" id="v2-ctxpanel">
    <div class="v2-ctx-scroll">

    {{-- ================= HOME (active by default so menu shows even
         before JS has a chance to hydrate from URL or localStorage) ===== --}}
    <div class="v2-ctx-section v2-is-on" data-section="home">
        <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('home') }}</div></div>
        <div class="v2-ctx-group v2-is-pinned" style="display:none;">
            <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
            <div class="v2-ctx-group-body"></div>
        </div>
        <div class="v2-ctx-group">
            <div class="v2-ctx-group-head"><span>{{ translate('overview') }}</span></div>
            <a class="v2-nav-item {{ Request::is('admin/dashboard') ? 'v2-is-active' : '' }}" data-item="dashboard" href="{{ route('admin.dashboard.index') }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('dashboard') }}</span></span>
                <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="dashboard" aria-label="Pin"></button></div>
            </a>
            @if (Helpers::module_permission_check('pos'))
                <a class="v2-nav-item {{ Request::is('admin/pos*') ? 'v2-is-active' : '' }}" data-item="pos" href="{{ route('admin.pos.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('POS') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="pos" aria-label="Pin"></button></div>
                </a>
            @endif
        </div>
    </div>

    {{-- ================= CATALOG ================= --}}
    @if (Helpers::module_permission_check('catalog'))
        <div class="v2-ctx-section" data-section="catalog">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('catalog') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('In_House_Products') }}</span></div>
                <a class="v2-nav-item {{ (Request::is('admin/products/list/in-house*') || Request::is('admin/products/view/in-house/*') || Request::is('admin/products/barcode/*') || Request::is('admin/products/update/*')) ? 'v2-is-active' : '' }}" data-item="inhouse-products" href="{{ route('admin.products.list', ['in-house']) }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Product_List') }}</span></span>
                    <div class="v2-nav-right">
                        <span data-v2-tag="success">{{ getAdminProductsCount('all') }}</span>
                        <button class="v2-pin-btn" type="button" data-pin="inhouse-products" aria-label="Pin"></button>
                    </div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/products/add') ? 'v2-is-active' : '' }}" data-item="add-product" href="{{ route('admin.products.add') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('add_New_Product') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="add-product" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/products/stock-limit-list/in_house') ? 'v2-is-active' : '' }}" data-item="limited-stock" href="{{ route('admin.products.stock-limit-list', ['in_house']) }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Limited_Stock') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="limited-stock" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/products/request-restock-list') ? 'v2-is-active' : '' }}" data-item="restock-requests" href="{{ route('admin.products.request-restock-list') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Request_Restock_List') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="restock-requests" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/products/bulk-import') ? 'v2-is-active' : '' }}" data-item="bulk-import" href="{{ route('admin.products.bulk-import') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Bulk_Import') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="bulk-import" aria-label="Pin"></button></div>
                </a>
            </div>

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('vendor_Products') }}</span></div>
                <a class="v2-nav-item {{ str_contains(url()->current().'?request_status='.request()->get('request_status'),'admin/products/list/vendor?request_status=0') ? 'v2-is-active' : '' }}" data-item="vendor-new-products" href="{{ route('admin.products.list', ['vendor', 'request_status' => '0']) }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('new_Products_Requests') }}</span></span>
                    <div class="v2-nav-right">
                        <span data-v2-tag="danger">{{ getVendorProductsCount(type: 'new-product', panel: 'admin') }}</span>
                        <button class="v2-pin-btn" type="button" data-pin="vendor-new-products" aria-label="Pin"></button>
                    </div>
                </a>
                @if (getWebConfig(name: 'product_wise_shipping_cost_approval') == 1)
                    <a class="v2-nav-item {{ Request::is('admin/products/updated-product-list/vendor') ? 'v2-is-active' : '' }}" data-item="vendor-updates" href="{{ route('admin.products.updated-product-list', ['type' => 'vendor']) }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_update_requests') }}</span></span>
                        <div class="v2-nav-right">
                            <span data-v2-tag="info">{{ getVendorProductsCount(type: 'product-updated-request', panel: 'admin') }}</span>
                            <button class="v2-pin-btn" type="button" data-pin="vendor-updates" aria-label="Pin"></button>
                        </div>
                    </a>
                @endif
                <a class="v2-nav-item {{ str_contains(url()->current().'?request_status='.request()->get('request_status'),'/admin/products/list/vendor?request_status=1') ? 'v2-is-active' : '' }}" data-item="vendor-approved" href="{{ route('admin.products.list', ['vendor', 'request_status' => '1']) }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('approved_Products') }}</span></span>
                    <div class="v2-nav-right">
                        <span data-v2-tag="success">{{ getVendorProductsCount(type: 'approved', panel: 'admin') }}</span>
                        <button class="v2-pin-btn" type="button" data-pin="vendor-approved" aria-label="Pin"></button>
                    </div>
                </a>
                <a class="v2-nav-item {{ str_contains(url()->current().'?request_status='.request()->get('request_status'),'/admin/products/list/vendor?request_status=2') ? 'v2-is-active' : '' }}" data-item="vendor-denied" href="{{ route('admin.products.list', ['vendor', 'request_status' => '2']) }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('denied_Products') }}</span></span>
                    <div class="v2-nav-right">
                        <span data-v2-tag="danger">{{ getVendorProductsCount(type: 'denied', panel: 'admin') }}</span>
                        <button class="v2-pin-btn" type="button" data-pin="vendor-denied" aria-label="Pin"></button>
                    </div>
                </a>
            </div>

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('organization') }}</span></div>
                <div class="v2-nav-item v2-has-children {{ (Request::is('admin/category/*') || Request::is('admin/sub-category/*') || Request::is('admin/sub-sub-category/*')) ? 'v2-is-active' : '' }}" data-item="category-setup">
                    <a class="v2-nav-btn" href="{{ route('admin.category.view') }}">
                        <span class="v2-nav-label">{{ translate('category_Setup') }}</span>
                    </a>
                    <div class="v2-nav-right">
                        <button class="v2-pin-btn" type="button" data-pin="category-setup" aria-label="Pin"></button>
                        <span class="v2-nav-chev" aria-label="{{ translate('toggle') }}">
                            <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                        </span>
                    </div>
                </div>
                <div class="v2-nav-children v2-is-collapsed" data-children-for="category-setup">
                    <a class="v2-nav-child {{ Request::is('admin/category/*') ? 'v2-is-on' : '' }}" href="{{ route('admin.category.view') }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('categories') }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/sub-category/*') ? 'v2-is-on' : '' }}" href="{{ route('admin.sub-category.view') }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('sub_Categories') }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/sub-sub-category/*') ? 'v2-is-on' : '' }}" href="{{ route('admin.sub-sub-category.view') }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('sub_Sub_Categories') }}</span>
                    </a>
                </div>
                <a class="v2-nav-item {{ Request::is('admin/brand/list') ? 'v2-is-active' : '' }}" data-item="brands" href="{{ route('admin.brand.list') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Brand_Setup') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="brands" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/attribute*') ? 'v2-is-active' : '' }}" data-item="attributes" href="{{ route('admin.attribute.view') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_Attribute_Setup') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="attributes" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/products/product-gallery') ? 'v2-is-active' : '' }}" data-item="product-gallery" href="{{ route('admin.products.product-gallery') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Product_Gallery') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="product-gallery" aria-label="Pin"></button></div>
                </a>
            </div>
        </div>
    @endif

    {{-- ================= ORDERS ================= --}}
    @if (Helpers::module_permission_check('orders'))
        <div class="v2-ctx-section" data-section="orders">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('orders') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('sales') }}</span></div>

                <div class="v2-nav-item v2-has-children {{ Request::is('admin/orders/list*') ? 'v2-is-active' : '' }}" data-item="orders-all">
                    <a class="v2-nav-btn" href="{{ route('admin.orders.list', ['all']) }}">
                        <span class="v2-nav-label">{{ translate('orders') }}</span>
                    </a>
                    <div class="v2-nav-right">
                        @if (($sidebarOrderCounts['pending'] ?? 0) > 0)
                            <span data-v2-tag="primary">{{ $sidebarOrderCounts['pending'] }}</span>
                        @endif
                        <button class="v2-pin-btn" type="button" data-pin="orders-all" aria-label="Pin"></button>
                        <span class="v2-nav-chev" aria-label="{{ translate('toggle') }}">
                            <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                        </span>
                    </div>
                </div>
                <div class="v2-nav-children v2-is-collapsed" data-children-for="orders-all">
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/all') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['all']) }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('all') }}</span>
                        <span class="v2-nav-child-count" data-tone="primary">{{ $sidebarTotalOrders ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/pending') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['pending']) }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('pending') }}</span>
                        <span class="v2-nav-child-count" data-tone="primary">{{ $sidebarOrderCounts['pending'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/confirmed') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['confirmed']) }}">
                        <span class="v2-nav-child-dot" data-tone="success"></span>
                        <span class="v2-nav-child-label">{{ translate('confirmed') }}</span>
                        <span class="v2-nav-child-count" data-tone="success">{{ $sidebarOrderCounts['confirmed'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/processing') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['processing']) }}">
                        <span class="v2-nav-child-dot" data-tone="warn"></span>
                        <span class="v2-nav-child-label">{{ translate('packaging') }}</span>
                        <span class="v2-nav-child-count" data-tone="warn">{{ $sidebarOrderCounts['processing'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/out_for_delivery') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['out_for_delivery']) }}">
                        <span class="v2-nav-child-dot" data-tone="warn"></span>
                        <span class="v2-nav-child-label">{{ translate('out_for_delivery') }}</span>
                        <span class="v2-nav-child-count" data-tone="warn">{{ $sidebarOrderCounts['out_for_delivery'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/delivered') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['delivered']) }}">
                        <span class="v2-nav-child-dot" data-tone="success"></span>
                        <span class="v2-nav-child-label">{{ translate('delivered') }}</span>
                        <span class="v2-nav-child-count" data-tone="success">{{ $sidebarOrderCounts['delivered'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/returned') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['returned']) }}">
                        <span class="v2-nav-child-dot" data-tone="danger"></span>
                        <span class="v2-nav-child-label">{{ translate('returned') }}</span>
                        <span class="v2-nav-child-count" data-tone="danger">{{ $sidebarOrderCounts['returned'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/failed') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['failed']) }}">
                        <span class="v2-nav-child-dot" data-tone="danger"></span>
                        <span class="v2-nav-child-label">{{ translate('failed_to_Deliver') }}</span>
                        <span class="v2-nav-child-count" data-tone="danger">{{ $sidebarOrderCounts['failed'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/orders/list/canceled') ? 'v2-is-on' : '' }}" href="{{ route('admin.orders.list', ['canceled']) }}">
                        <span class="v2-nav-child-dot" data-tone="neutral"></span>
                        <span class="v2-nav-child-label">{{ translate('canceled') }}</span>
                        <span class="v2-nav-child-count" data-tone="danger">{{ $sidebarOrderCounts['canceled'] ?? 0 }}</span>
                    </a>
                </div>

                <div class="v2-nav-item v2-has-children {{ Request::is('admin/refund-section/refund/*') ? 'v2-is-active' : '' }}" data-item="refunds">
                    <a class="v2-nav-btn" href="{{ route('admin.refund-section.refund.list', ['pending']) }}">
                        <span class="v2-nav-label">{{ translate('refund_Requests') }}</span>
                    </a>
                    <div class="v2-nav-right">
                        @if (($sidebarRefundCounts['pending'] ?? 0) > 0)
                            <span data-v2-tag="warn">{{ $sidebarRefundCounts['pending'] }}</span>
                        @endif
                        <button class="v2-pin-btn" type="button" data-pin="refunds" aria-label="Pin"></button>
                        <span class="v2-nav-chev">
                            <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                        </span>
                    </div>
                </div>
                <div class="v2-nav-children v2-is-collapsed" data-children-for="refunds">
                    <a class="v2-nav-child {{ Request::is('admin/refund-section/refund/list/pending') ? 'v2-is-on' : '' }}" href="{{ route('admin.refund-section.refund.list', ['pending']) }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('pending') }}</span>
                        <span class="v2-nav-child-count" data-tone="primary">{{ $sidebarRefundCounts['pending'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/refund-section/refund/list/approved') ? 'v2-is-on' : '' }}" href="{{ route('admin.refund-section.refund.list', ['approved']) }}">
                        <span class="v2-nav-child-dot" data-tone="success"></span>
                        <span class="v2-nav-child-label">{{ translate('approved') }}</span>
                        <span class="v2-nav-child-count" data-tone="success">{{ $sidebarRefundCounts['approved'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/refund-section/refund/list/refunded') ? 'v2-is-on' : '' }}" href="{{ route('admin.refund-section.refund.list', ['refunded']) }}">
                        <span class="v2-nav-child-dot" data-tone="success"></span>
                        <span class="v2-nav-child-label">{{ translate('refunded') }}</span>
                        <span class="v2-nav-child-count" data-tone="success">{{ $sidebarRefundCounts['refunded'] ?? 0 }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/refund-section/refund/list/rejected') ? 'v2-is-on' : '' }}" href="{{ route('admin.refund-section.refund.list', ['rejected']) }}">
                        <span class="v2-nav-child-dot" data-tone="danger"></span>
                        <span class="v2-nav-child-label">{{ translate('rejected') }}</span>
                        <span class="v2-nav-child-count" data-tone="danger">{{ $sidebarRefundCounts['rejected'] ?? 0 }}</span>
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= AUCTION ================= --}}
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
                        @php
                            $isInhouseRoute = str_contains($v2AuctionRoute['path'], '/inhouse/');
                            $badgeCounts = $isInhouseRoute ? ($sidebarAuctionInhouseCounts ?? []) : ($sidebarAuctionVendorCounts ?? []);
                            $badgeData = function_exists('getAuctionSidebarBadgeData') ? getAuctionSidebarBadgeData($v2AuctionRoute['path'], $badgeCounts) : ['is_status_route' => false, 'badge_class' => 'neutral', 'count' => 0];
                            $v2Slug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($v2AuctionGroup['name'] . '-' . $v2AuctionRoute['name']));
                            $v2Tone = match($badgeData['badge_class'] ?? 'neutral') {
                                'success' => 'success',
                                'danger' => 'danger',
                                'warning' => 'warn',
                                'info' => 'info',
                                default => 'primary',
                            };
                        @endphp
                        <a class="v2-nav-item {{ (strstr(Request::url(), $v2AuctionRoute['path']) || (!empty($v2AuctionRoute['active_patterns']) && Request::is($v2AuctionRoute['active_patterns']))) ? 'v2-is-active' : '' }}" data-item="auction-{{ $v2Slug }}" href="{{ $v2AuctionRoute['url'] }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($v2AuctionRoute['name']) }}</span></span>
                            <div class="v2-nav-right">
                                @if ($badgeData['is_status_route'])
                                    <span data-v2-tag="{{ $v2Tone }}">{{ $badgeData['count'] }}</span>
                                @endif
                                <button class="v2-pin-btn" type="button" data-pin="auction-{{ $v2Slug }}" aria-label="Pin"></button>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endforeach

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('Finance') }}</span></div>
                <a class="v2-nav-item {{ Request::is('admin/auction/auction-withdraw*') ? 'v2-is-active' : '' }}" data-item="auction-withdraw" href="{{ route('admin.auction.auction-withdraw') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Withdraw_List') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="auction-withdraw" aria-label="Pin"></button></div>
                </a>
                <div class="v2-nav-item v2-has-children {{ Request::is('admin/auction/entry-fee-payments*') ? 'v2-is-active' : '' }}" data-item="auction-entry-fee-payments">
                    <a class="v2-nav-btn" href="{{ route('admin.auction.entry-fee-payments', ['method' => 'offline']) }}">
                        <span class="v2-nav-label">{{ translate('Entry_Fee_Payments') }}</span>
                    </a>
                    <div class="v2-nav-right">
                        <button class="v2-pin-btn" type="button" data-pin="auction-entry-fee-payments" aria-label="Pin"></button>
                        <span class="v2-nav-chev" aria-label="{{ translate('toggle') }}">
                            <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                        </span>
                    </div>
                </div>
                <div class="v2-nav-children v2-is-collapsed" data-children-for="auction-entry-fee-payments">
                    <a class="v2-nav-child {{ Request::is('admin/auction/entry-fee-payments/digital*') ? 'v2-is-on' : '' }}"
                       href="{{ route('admin.auction.entry-fee-payments', ['method' => 'digital']) }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('Digital_Payments') }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/auction/entry-fee-payments/offline*') ? 'v2-is-on' : '' }}"
                       href="{{ route('admin.auction.entry-fee-payments', ['method' => 'offline']) }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('Offline_Payments') }}</span>
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MARKETING ================= --}}
    @if (Helpers::module_permission_check('marketing'))
        <div class="v2-ctx-section" data-section="marketing">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('marketing') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            @if (Helpers::module_permission_check('marketing'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('promotions') }}</span></div>
                    <a class="v2-nav-item {{ Request::is('admin/banner*') ? 'v2-is-active' : '' }}" data-item="banners" href="{{ route('admin.banner.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('banner_Setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="banners" aria-label="Pin"></button></div>
                    </a>

                    <div class="v2-nav-item v2-has-children {{ (Request::is('admin/coupon*') || Request::is('admin/deal*')) ? 'v2-is-active' : '' }}" data-item="offers">
                        <a class="v2-nav-btn" href="{{ route('admin.coupon.add') }}">
                            <span class="v2-nav-label">{{ translate('offers_&_Deals') }}</span>
                        </a>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="offers" aria-label="Pin"></button>
                            <span class="v2-nav-chev">
                                <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="v2-nav-children v2-is-collapsed" data-children-for="offers">
                        <a class="v2-nav-child {{ Request::is('admin/coupon*') ? 'v2-is-on' : '' }}" href="{{ route('admin.coupon.add') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('coupon') }}</span>
                        </a>
                        <a class="v2-nav-child {{ (Request::is('admin/deal/flash') || Request::is('admin/deal/flash-add') || Request::is('admin/deal/update*')) ? 'v2-is-on' : '' }}" href="{{ route('admin.deal.flash') }}">
                            <span class="v2-nav-child-dot" data-tone="warn"></span>
                            <span class="v2-nav-child-label">{{ translate('flash_Deals') }}</span>
                        </a>
                        <a class="v2-nav-child {{ (Request::is('admin/deal/day') || Request::is('admin/deal/day-update*')) ? 'v2-is-on' : '' }}" href="{{ route('admin.deal.day') }}">
                            <span class="v2-nav-child-dot" data-tone="warn"></span>
                            <span class="v2-nav-child-label">{{ translate('deal_of_the_day') }}</span>
                        </a>
                        <a class="v2-nav-child {{ (Request::is('admin/deal/feature') || Request::is('admin/deal/feature/new') || Request::is('admin/deal/feature-update*')) ? 'v2-is-on' : '' }}" href="{{ route('admin.deal.feature') }}">
                            <span class="v2-nav-child-dot" data-tone="success"></span>
                            <span class="v2-nav-child-label">{{ translate('featured_Deal') }}</span>
                        </a>
                        <a class="v2-nav-child {{ Request::is('admin/deal/clearance-sale*') ? 'v2-is-on' : '' }}" href="{{ route('admin.deal.clearance-sale.index') }}">
                            <span class="v2-nav-child-dot" data-tone="danger"></span>
                            <span class="v2-nav-child-label">{{ translate('Clearance_Sale') }}</span>
                        </a>
                    </div>

                </div>

                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('communication') }}</span></div>
                    <a class="v2-nav-item {{ (!Request::is('admin/notification/push') && Request::is('admin/notification/*')) ? 'v2-is-active' : '' }}" data-item="send-notification" href="{{ route('admin.notification.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('send_notification') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="send-notification" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/push-notification/index*') ? 'v2-is-active' : '' }}" data-item="push-setup" href="{{ route('admin.push-notification.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('push_notifications_setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="push-setup" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/business-settings/announcement*') ? 'v2-is-active' : '' }}" data-item="announcement" href="{{ route('admin.business-settings.announcement') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('announcement') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="announcement" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (Helpers::module_permission_check('marketing') && Route::has('admin.blog.view'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('content') }}</span></div>
                    <div class="v2-nav-item v2-has-children {{ Request::is('admin/blog*') ? 'v2-is-active' : '' }}" data-item="blog">
                        <a class="v2-nav-btn" href="{{ route('admin.blog.view') }}">
                            <span class="v2-nav-label">{{ translate('blog_setup') }}</span>
                        </a>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="blog" aria-label="Pin"></button>
                            <span class="v2-nav-chev">
                                <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="v2-nav-children v2-is-collapsed" data-children-for="blog">
                        <a class="v2-nav-child {{ Request::is('admin/blog/add') ? 'v2-is-on' : '' }}" href="{{ route('admin.blog.add') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('add_new') }}</span>
                        </a>
                        <a class="v2-nav-child {{ (Request::is('admin/blog/view') || Request::is('admin/blog/app-download-setup') || Request::is('admin/blog/priority-setup')) ? 'v2-is-on' : '' }}" href="{{ route('admin.blog.view') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('blog_list') }}</span>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ================= PEOPLE ================= --}}
    @if (Helpers::module_permission_check('people'))
        <div class="v2-ctx-section" data-section="people">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('people') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            @if (Helpers::module_permission_check('people'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('customers') }}</span></div>

                    <a class="v2-nav-item {{ Request::is('admin/customer/list') || Request::is('admin/customer/view*') ? 'v2-is-active' : '' }}" data-item="customers" href="{{ route('admin.customer.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('customer_List') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="customers" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/reviews*') ? 'v2-is-active' : '' }}" data-item="reviews" href="{{ route('admin.reviews.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('customer_Reviews') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="reviews" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/customer/wallet/report') ? 'v2-is-active' : '' }}" data-item="wallet" href="{{ route('admin.customer.wallet.report') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('wallet') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="wallet" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/customer/wallet/bonus-setup') || Request::is('admin/customer/wallet/bonus-setup/edit/*')) ? 'v2-is-active' : '' }}" data-item="wallet-bonus" href="{{ route('admin.customer.wallet.bonus-setup') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('wallet_Bonus_Setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="wallet-bonus" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/customer/loyalty/report') ? 'v2-is-active' : '' }}" data-item="loyalty" href="{{ route('admin.customer.loyalty.report') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('loyalty_Points') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="loyalty" aria-label="Pin"></button></div>
                    </a>

                    <a class="v2-nav-item {{ Request::is('admin/customer/subscriber-list') ? 'v2-is-active' : '' }}" data-item="subscribers" href="{{ route('admin.customer.subscriber-list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('subscribers') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="subscribers" aria-label="Pin"></button></div>
                    </a>
                </div>

                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('vendors') }}</span></div>
                    <a class="v2-nav-item {{ Request::is('admin/vendors/add') ? 'v2-is-active' : '' }}" data-item="vendor-add" href="{{ route('admin.vendors.add') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('add_New_Vendor') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="vendor-add" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/vendors/list') || Request::is('admin/vendors/view*')) ? 'v2-is-active' : '' }}" data-item="vendor-list" href="{{ route('admin.vendors.vendor-list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('vendor_List') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="vendor-list" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/vendors/withdraw-list') || Request::is('admin/vendors/withdraw-view/*')) ? 'v2-is-active' : '' }}" data-item="vendor-withdraw" href="{{ route('admin.vendors.withdraw_list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('withdraws') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="vendor-withdraw" aria-label="Pin"></button></div>
                    </a>
                </div>

                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('deliveryman') }}</span></div>
                    <a class="v2-nav-item {{ (Request::is('admin/delivery-man/list') || Request::is('admin/delivery-man/update*') || Request::is('admin/delivery-man/order-history-log*') || Request::is('admin/delivery-man/order-wise-earning*')) ? 'v2-is-active' : '' }}" data-item="delivery" href="{{ route('admin.delivery-man.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('delivery_men') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="delivery" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/delivery-man/add') ? 'v2-is-active' : '' }}" data-item="dm-add" href="{{ route('admin.delivery-man.add') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('add_new_delivery_man') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="dm-add" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/delivery-man/withdraw-list') || Request::is('admin/delivery-man/withdraw-view*')) ? 'v2-is-active' : '' }}" data-item="dm-withdraw" href="{{ route('admin.delivery-man.withdraw-list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('withdraws') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="dm-withdraw" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/delivery-man/emergency-contact') ? 'v2-is-active' : '' }}" data-item="dm-emergency" href="{{ route('admin.delivery-man.emergency-contact.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Emergency_Contact') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="dm-emergency" aria-label="Pin"></button></div>
                    </a>
                </div>

                @if (auth('admin')->user()->admin_role_id == 1)
                    <div class="v2-ctx-group">
                        <div class="v2-ctx-group-head"><span>{{ translate('team') }}</span></div>
                        <a class="v2-nav-item {{ (Request::is('admin/employee/list') || Request::is('admin/employee/add') || Request::is('admin/employee/update*')) ? 'v2-is-active' : '' }}" data-item="employees" href="{{ route('admin.employee.list') }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('employees') }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="employees" aria-label="Pin"></button></div>
                        </a>
                        <a class="v2-nav-item {{ Request::is('admin/custom-role*') ? 'v2-is-active' : '' }}" data-item="roles" href="{{ route('admin.custom-role.create') }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('roles_and_permissions') }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="roles" aria-label="Pin"></button></div>
                        </a>
                    </div>
                @endif
            @endif

            @if (Helpers::module_permission_check('people'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('support') }}</span></div>
                    <a class="v2-nav-item {{ Request::is('admin/messages*') ? 'v2-is-active' : '' }}" data-item="inbox" href="{{ route('admin.messages.index', ['type' => 'customer']) }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('inbox') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="inbox" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/support-ticket*') ? 'v2-is-active' : '' }}" data-item="support-ticket" href="{{ route('admin.support-ticket.view') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('support_Ticket') }}</span></span>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="support-ticket" aria-label="Pin"></button>
                        </div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/contact*') ? 'v2-is-active' : '' }}" data-item="contact-form" href="{{ route('admin.contact.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('contact_form') }}</span></span>
                        <div class="v2-nav-right">
                            @if (($sidebarUnseenMessages ?? 0) > 0)
                                <span data-v2-tag="danger">{{ $sidebarUnseenMessages }}</span>
                            @endif
                            <button class="v2-pin-btn" type="button" data-pin="contact-form" aria-label="Pin"></button>
                        </div>
                    </a>
                </div>
            @endif
        </div>
    @endif

    {{-- ================= REPORTS ================= --}}
    @if (Helpers::module_permission_check('reports') || Helpers::module_permission_check('marketplace'))
        <div class="v2-ctx-section" data-section="reports">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('reports') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            @if (Helpers::module_permission_check('reports'))
                <div class="v2-ctx-group">
                    <a class="v2-nav-item {{ Request::is('admin/analytics*') ? 'v2-is-active' : '' }}" data-item="analytics" href="{{ route('admin.analytics.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Analytics') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="analytics" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('finance') }}</span></div>
                <a class="v2-nav-item {{ (Request::is('admin/report/admin-earning') || Request::is('admin/report/vendor-earning')) ? 'v2-is-active' : '' }}" data-item="earnings" href="{{ route('admin.report.admin-earning') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('earnings') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="earnings" aria-label="Pin"></button></div>
                </a>

                <div class="v2-nav-item v2-has-children {{ (Request::is('admin/report/inhouse-product-sale') || Request::is('admin/report/vendor-report') || Request::is('admin/transaction/order-transaction-list') || Request::is('admin/transaction/expense-transaction-list') || Request::is('admin/report/transaction/refund-transaction-list') || Request::is('admin/transaction/wallet-bonus')) ? 'v2-is-active' : '' }}" data-item="sales-report">
                    <a class="v2-nav-btn" href="{{ route('admin.report.inhouse-product-sale') }}">
                        <span class="v2-nav-label">{{ translate('sales_and_transactions') }}</span>
                    </a>
                    <div class="v2-nav-right">
                        <button class="v2-pin-btn" type="button" data-pin="sales-report" aria-label="Pin"></button>
                        <span class="v2-nav-chev">
                            <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                        </span>
                    </div>
                </div>
                <div class="v2-nav-children v2-is-collapsed" data-children-for="sales-report">
                    <a class="v2-nav-child {{ Request::is('admin/report/inhouse-product-sale') ? 'v2-is-on' : '' }}" href="{{ route('admin.report.inhouse-product-sale') }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('inhouse_Sales') }}</span>
                    </a>
                    <a class="v2-nav-child {{ Request::is('admin/report/vendor-report') ? 'v2-is-on' : '' }}" href="{{ route('admin.report.vendor-report') }}">
                        <span class="v2-nav-child-dot" data-tone="primary"></span>
                        <span class="v2-nav-child-label">{{ translate('vendor_Sales') }}</span>
                    </a>
                    <a class="v2-nav-child {{ (Request::is('admin/transaction/order-transaction-list') || Request::is('admin/transaction/expense-transaction-list') || Request::is('admin/transaction/refund-transaction-list') || Request::is('admin/report/transaction/refund-transaction-list') || Request::is('admin/transaction/wallet-bonus')) ? 'v2-is-on' : '' }}" href="{{ route('admin.transaction.order-transaction-list') }}">
                        <span class="v2-nav-child-dot" data-tone="success"></span>
                        <span class="v2-nav-child-label">{{ translate('transactions') }}</span>
                    </a>
                </div>
            </div>

            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('operations') }}</span></div>
                <a class="v2-nav-item {{ (Request::is('admin/report/all-product') || Request::is('admin/stock/product-in-wishlist') || Request::is('admin/stock/product-stock')) ? 'v2-is-active' : '' }}" data-item="product-report" href="{{ route('admin.report.all-product') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('product_Report') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="product-report" aria-label="Pin"></button></div>
                </a>
                <a class="v2-nav-item {{ Request::is('admin/report/order') ? 'v2-is-active' : '' }}" data-item="order-report" href="{{ route('admin.report.order') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('order_Report') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="order-report" aria-label="Pin"></button></div>
                </a>
            </div>

            @if (Helpers::module_permission_check('marketplace'))
            <div class="v2-ctx-group">
                <div class="v2-ctx-group-head"><span>{{ translate('marketplace') }}</span></div>

                @if (Route::has('admin.marketplace.settlements.index'))
                    <div class="v2-nav-item v2-has-children {{ (Request::is('admin/marketplace/settlements*') || Request::is('admin/marketplace/ledger*') || Request::is('admin/marketplace/commission-rules*') || Request::is('admin/marketplace/reconciliation*') || Request::is('admin/marketplace/payment-routing*') || Request::is('admin/marketplace/exchange-rates*')) ? 'v2-is-active' : '' }}" data-item="marketplace-finance">
                        <a class="v2-nav-btn" href="{{ route('admin.marketplace.settlements.index') }}">
                            <span class="v2-nav-label">{{ translate('marketplace_finance') }}</span>
                        </a>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="marketplace-finance" aria-label="Pin"></button>
                            <span class="v2-nav-chev">
                                <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="v2-nav-children v2-is-collapsed" data-children-for="marketplace-finance">
                        <a class="v2-nav-child {{ (Request::is('admin/marketplace/settlements*') || Request::is('admin/marketplace/ledger*')) ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.settlements.index') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('vendor_settlements') }}</span>
                        </a>
                        @if (Route::has('admin.marketplace.payouts.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/payouts*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.payouts.index') }}">
                                <span class="v2-nav-child-dot" data-tone="success"></span>
                                <span class="v2-nav-child-label">{{ translate('seller_payouts') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.commission-rules.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/commission-rules*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.commission-rules.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('commission_rules') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.reconciliation'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/reconciliation*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.reconciliation') }}">
                                <span class="v2-nav-child-dot" data-tone="success"></span>
                                <span class="v2-nav-child-label">{{ translate('financial_reconciliation') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.payment-routing.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/payment-routing*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.payment-routing.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('payment_orchestration') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.exchange-rates.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/exchange-rates*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.exchange-rates.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('exchange_rates') }}</span>
                            </a>
                        @endif
                    </div>
                @endif

                @if (Route::has('admin.marketplace.approvals.index'))
                    <div class="v2-nav-item v2-has-children {{ (Request::is('admin/marketplace/approvals*') || Request::is('admin/marketplace/seller-verification*') || Request::is('admin/marketplace/seller-scorecard*') || Request::is('admin/marketplace/sla*') || Request::is('admin/marketplace/product-moderation*') || Request::is('admin/marketplace/category-governance*') || Request::is('admin/marketplace/seller-operations*')) ? 'v2-is-active' : '' }}" data-item="marketplace-sellers">
                        <a class="v2-nav-btn" href="{{ route('admin.marketplace.approvals.index') }}">
                            <span class="v2-nav-label">{{ translate('seller_governance') }}</span>
                        </a>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="marketplace-sellers" aria-label="Pin"></button>
                            <span class="v2-nav-chev">
                                <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="v2-nav-children v2-is-collapsed" data-children-for="marketplace-sellers">
                        @if (Route::has('admin.marketplace.seller-operations.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/seller-operations*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.seller-operations.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('seller_operations') }}</span>
                            </a>
                        @endif
                        <a class="v2-nav-child {{ Request::is('admin/marketplace/approvals*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.approvals.index') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('approvals') }}</span>
                        </a>
                        @if (Route::has('admin.marketplace.seller-verification.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/seller-verification*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.seller-verification.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('seller_verification') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.brand-registry.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/brand-registry*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.brand-registry.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('brand_registry') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.seller-scorecard.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/seller-scorecard*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.seller-scorecard.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('seller_scorecard') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.sla.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/sla*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.sla.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('seller_sla') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.product-moderation.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/product-moderation*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.product-moderation.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('product_moderation') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.category-governance.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/category-governance*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.category-governance.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('category_governance') }}</span>
                            </a>
                        @endif
                    </div>
                @endif

                @if (Route::has('admin.marketplace.suppliers.index'))
                    <div class="v2-nav-item v2-has-children {{ (Request::is('admin/marketplace/suppliers*') || Request::is('admin/marketplace/purchase-orders*') || Request::is('admin/marketplace/inventory-adjustments*') || Request::is('admin/marketplace/batches*') || Request::is('admin/marketplace/warehouses*') || Request::is('admin/marketplace/returns*') || Request::is('admin/marketplace/fulfillments*') || Request::is('admin/marketplace/shipping-zones*')) ? 'v2-is-active' : '' }}" data-item="marketplace-supply">
                        <a class="v2-nav-btn" href="{{ route('admin.marketplace.suppliers.index') }}">
                            <span class="v2-nav-label">{{ translate('supply_chain') }}</span>
                        </a>
                        <div class="v2-nav-right">
                            <button class="v2-pin-btn" type="button" data-pin="marketplace-supply" aria-label="Pin"></button>
                            <span class="v2-nav-chev">
                                <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="v2-nav-children v2-is-collapsed" data-children-for="marketplace-supply">
                        <a class="v2-nav-child {{ Request::is('admin/marketplace/suppliers*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.suppliers.index') }}">
                            <span class="v2-nav-child-dot" data-tone="primary"></span>
                            <span class="v2-nav-child-label">{{ translate('suppliers') }}</span>
                        </a>
                        @if (Route::has('admin.marketplace.purchase-orders.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/purchase-orders*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.purchase-orders.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('purchase_orders') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.inventory-adjustments.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/inventory-adjustments*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.inventory-adjustments.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('inventory_adjustments') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.batches.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/batches*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.batches.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('batches_and_expiry') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.warehouses.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/warehouses*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.warehouses.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('warehouses') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.returns.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/returns*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.returns.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('returns') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.fulfillments.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/fulfillments*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.fulfillments.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('fulfilment') }}</span>
                            </a>
                        @endif
                        @if (Route::has('admin.marketplace.shipping-zones.index'))
                            <a class="v2-nav-child {{ Request::is('admin/marketplace/shipping-zones*') ? 'v2-is-on' : '' }}" href="{{ route('admin.marketplace.shipping-zones.index') }}">
                                <span class="v2-nav-child-dot" data-tone="primary"></span>
                                <span class="v2-nav-child-label">{{ translate('shipping_zones') }}</span>
                            </a>
                        @endif
                    </div>
                @endif

                @if (Route::has('admin.marketplace.audit-log'))
                    <a class="v2-nav-item {{ Request::is('admin/marketplace/audit-log*') ? 'v2-is-active' : '' }}" data-item="marketplace-audit-log" href="{{ route('admin.marketplace.audit-log') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('audit_log') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="marketplace-audit-log" aria-label="Pin"></button></div>
                    </a>
                @endif
            </div>
            @endif

            @if ($v2Tax && count($v2TaxReportRoutes))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('tax') }}</span></div>
                    @foreach ($v2TaxReportRoutes as $v2TaxReport)
                        @php
                            $isActive = strstr(Request::url(), $v2TaxReport['path']);
                            if (!$isActive && isset($v2TaxReport['sub_routes'])) {
                                foreach ($v2TaxReport['sub_routes'] as $sub) {
                                    if (strstr(Request::url(), $sub['path'])) { $isActive = true; break; }
                                }
                            }
                            $v2TaxSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower('tax-' . $v2TaxReport['name']));
                        @endphp
                        <a class="v2-nav-item {{ $isActive ? 'v2-is-active' : '' }}" data-item="{{ $v2TaxSlug }}" href="{{ $v2TaxReport['url'] }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($v2TaxReport['name']) }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="{{ $v2TaxSlug }}" aria-label="Pin"></button></div>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($v2Auction && count($v2AuctionReportRoutes))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('Auction') }}</span></div>
                    @foreach ($v2AuctionReportRoutes as $v2AuctionReport)
                        <?php
                            $isActive = strstr(Request::url(), $v2AuctionReport['path']);
                            if (!$isActive && isset($v2AuctionReport['sub_routes'])) {
                                foreach ($v2AuctionReport['sub_routes'] as $sub) {
                                    if (strstr(Request::url(), $sub['path'])) { $isActive = true; break; }
                                }
                            }
                            $v2AuctionSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower('auction-' . $v2AuctionReport['name']));
                        ?>
                        <a class="v2-nav-item {{ $isActive ? 'v2-is-active' : '' }}" data-item="{{ $v2AuctionSlug }}" href="{{ $v2AuctionReport['url'] }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($v2AuctionReport['name']) }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="{{ $v2AuctionSlug }}" aria-label="Pin"></button></div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ================= SETTINGS ================= --}}
    @if (Helpers::module_permission_check('business_settings') || Helpers::module_permission_check('system_settings') || Helpers::module_permission_check('3rd_party_setup') || Helpers::module_permission_check('themes_and_addons'))
        <div class="v2-ctx-section" data-section="settings">
            <div class="v2-ctx-head"><div class="v2-ctx-title">{{ translate('settings') }}</div></div>
            <div class="v2-ctx-group v2-is-pinned" style="display:none;">
                <div class="v2-ctx-group-head"><span>{{ translate('pinned') }}</span></div>
                <div class="v2-ctx-group-body"></div>
            </div>

            @if (Helpers::module_permission_check('system_settings'))
                <div class="v2-ctx-group">
                    <a class="v2-nav-item {{ Request::is('admin/monitoring*') ? 'v2-is-active' : '' }}" data-item="monitoring" href="{{ route('admin.monitoring.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Monitoring') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="monitoring" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/developer*') ? 'v2-is-active' : '' }}" data-item="developer" href="{{ route('admin.developer.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Developer_Portal') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="developer" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (Helpers::module_permission_check('business_settings'))
                <div class="v2-ctx-group">
                <a class="v2-nav-item {{ Request::is('admin/settings') ? 'v2-is-active' : '' }}" data-item="settings-index" href="{{ route('admin.settings.index') }}">
                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('All_Settings') }}</span></span>
                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="settings-index" aria-label="Pin"></button></div>
                </a>
            </div>
            <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('Business_Settings') }}</span></div>
                    <a class="v2-nav-item {{ (Request::is('admin/business-settings/web-config') || Request::is('admin/business-settings/refund-setup') || Request::is('admin/business-settings/website-setup') || Request::is('admin/business-settings/product-settings') || Request::is('admin/business-settings/payment-method/payment-option') || Request::is('admin/business-settings/vendor-settings') || Request::is('admin/business-settings/customer-settings') || Request::is('admin/business-settings/delivery-man-settings') || Request::is('admin/business-settings/shipping-method/update*') || Request::is('admin/business-settings/shipping-method/index') || Request::is('admin/business-settings/order-settings/index') || Request::is('admin/business-settings/invoice-settings') || Request::is('admin/business-settings/delivery-zone') || Request::is('admin/business-settings/auction-config')) ? 'v2-is-active' : '' }}" data-item="business-setup" href="{{ route('admin.business-settings.web-config.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Business_Setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="business-setup" aria-label="Pin"></button></div>
                    </a>

                    <a class="v2-nav-item {{ Request::is('admin/business-settings/inhouse-shop') || Request::is('admin/business-settings/inhouse-shop/setup*') ? 'v2-is-active' : '' }}" data-item="inhouse-shop" href="{{ route('admin.business-settings.inhouse-shop') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Inhouse_Shop') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="inhouse-shop" aria-label="Pin"></button></div>
                    </a>

                    @if ($v2Tax && count($v2TaxRoutes))
                        @php
                            $v2TaxActive = false;
                            foreach ($v2TaxRoutes as $v2TaxRow) {
                                if (strstr(Request::url(), $v2TaxRow['path'])) { $v2TaxActive = true; break; }
                            }
                        @endphp
                        <div class="v2-nav-item v2-has-children {{ $v2TaxActive ? 'v2-is-active' : '' }}" data-item="vat-setup">
                            <a class="v2-nav-btn" href="{{ $v2TaxRoutes[0]['url'] }}">
                                <span class="v2-nav-label">{{ translate('vat_tax_setup') }}</span>
                            </a>
                            <div class="v2-nav-right">
                                <button class="v2-pin-btn" type="button" data-pin="vat-setup" aria-label="Pin"></button>
                                <span class="v2-nav-chev">
                                    <svg width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 5 5 5-5 5"/></svg>
                                </span>
                            </div>
                        </div>
                        <div class="v2-nav-children v2-is-collapsed" data-children-for="vat-setup">
                            @foreach ($v2TaxRoutes as $v2TaxRow)
                                <a class="v2-nav-child {{ strstr(Request::url(), $v2TaxRow['path']) ? 'v2-is-on' : '' }}" href="{{ $v2TaxRow['url'] }}">
                                    <span class="v2-nav-child-dot" data-tone="primary"></span>
                                    <span class="v2-nav-child-label">{{ translate($v2TaxRow['name']) }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <a class="v2-nav-item {{ Request::is('admin/pages-and-media/vendor-registration-settings/*') ? 'v2-is-active' : '' }}" data-item="vendor-registration" href="{{ route('admin.pages-and-media.vendor-registration-settings.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('vendor_Registration') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="vendor-registration" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/business-settings/priority-setup') ? 'v2-is-active' : '' }}" data-item="priority-setup" href="{{ route('admin.business-settings.priority-setup.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Priority_Setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="priority-setup" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/auction/company-reliability') || (Request::is('admin/pages-and-media/list') || Request::is('admin/pages-and-media/page*') || Request::is('admin/pages-and-media/privacy-policy') || Request::is('admin/pages-and-media/about-us') || Request::is('admin/helpTopic/index') || Request::is('admin/pages-and-media/features-section') || Request::is('admin/pages-and-media/company-reliability')) ? 'v2-is-active' : '' }}" data-item="business-pages" href="{{ route('admin.pages-and-media.list') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('business_Pages') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="business-pages" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/pages-and-media/social-media') ? 'v2-is-active' : '' }}" data-item="social-media-links" href="{{ route('admin.pages-and-media.social-media') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('social_Media_Links') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="social-media-links" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/seo-settings/web-master-tool') || Request::is('admin/seo-settings/robot-txt') || Request::is('admin/seo-settings/sitemap') || Request::is('admin/seo-settings/robots-meta-content*') || Request::is('admin/seo-settings/error-logs/index') || Request::is('admin/seo-settings/redirects*') || Request::is('admin/seo-settings/translations*') || Request::is('admin/seo-settings/templates*')) ? 'v2-is-active' : '' }}" data-item="seo" href="{{ route('admin.seo-settings.web-master-tool') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('SEO_Settings') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="seo" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (Helpers::module_permission_check('system_settings'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('System_Settings') }}</span></div>
                    <a class="v2-nav-item {{ (Request::is('admin/system-setup/environment-setup') || Request::is('admin/system-setup/app-settings') || Request::is('admin/system-setup/app-deep-link') || Request::is('admin/system-setup/sitemap') || Request::is('admin/system-setup/currency/view') || Request::is('admin/system-setup/web-config/db-index') || Request::is('admin/system-setup/language*') || Request::is('admin/system-setup/software-update') || Request::is('admin/system-setup/web-config/app-settings') || Request::is('admin/system-setup/invoice-settings/') || Request::is('admin/system-setup/db-index')) ? 'v2-is-active' : '' }}" data-item="system-setup" href="{{ route('admin.system-setup.environment-setup') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('System_Setup') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="system-setup" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/system-setup/login-settings/login-url-setup') || Request::is('admin/system-setup/login-settings/customer-login-setup') || Request::is('admin/system-setup/login-settings/otp-setup')) ? 'v2-is-active' : '' }}" data-item="login-settings" href="{{ route('admin.system-setup.login-settings.customer-login-setup') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Login_Settings') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="login-settings" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/system-setup/email-templates/*') ? 'v2-is-active' : '' }}" data-item="email-template" href="{{ route('admin.system-setup.email-templates.view', ['admin', EmailTemplateKey::ADMIN_EMAIL_LIST[0]]) }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Email_Template') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="email-template" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/system-setup/file-manager*') ? 'v2-is-active' : '' }}" data-item="file-gallery" href="{{ route('admin.system-setup.file-manager.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Gallery') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="file-gallery" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (Helpers::module_permission_check('3rd_party_setup'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('3rd_Party_Setup') }}</span></div>
                    <a class="v2-nav-item {{ (Request::is('admin/third-party/withdraw-method/*') || Request::is('admin/third-party/payment-method') || Request::is('admin/third-party/offline-payment-method/index') || Request::is('admin/third-party/offline-payment-method*')) ? 'v2-is-active' : '' }}" data-item="payment-methods" href="{{ route('admin.third-party.payment-method.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Payment_Methods') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="payment-methods" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ (Request::is('admin/third-party/firebase-configuration/setup') || Request::is('admin/third-party/firebase-configuration/authentication')) ? 'v2-is-active' : '' }}" data-item="firebase" href="{{ route('admin.third-party.firebase-configuration.setup') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Firebase') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="firebase" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/third-party/analytics-index') ? 'v2-is-active' : '' }}" data-item="marketing-tools" href="{{ route('admin.third-party.analytics-index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Marketing_Tools') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="marketing-tools" aria-label="Pin"></button></div>
                    </a>
                    @if ($v2Ai)
                        <a class="v2-nav-item {{ (Request::is('admin/third-party/ai-setting') || Request::is('admin/third-party/ai-setting/vendors-usage-limits') || Request::is('admin/third-party/ai-setting/customers-usage-limits')) ? 'v2-is-active' : '' }}" data-item="ai-setup" href="{{ route('admin.third-party.ai-setting.index') }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('AI_Setup') }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="ai-setup" aria-label="Pin"></button></div>
                        </a>
                    @endif
                    <a class="v2-nav-item {{ (Request::is('admin/third-party/mail') || Request::is('admin/third-party/sms-module') || Request::is('admin/third-party/social-login/view') || Request::is('admin/third-party/social-media-chat/view') || Request::is('admin/third-party/storage-connection-settings/index') || Request::is('admin/third-party/map-api')) ? 'v2-is-active' : '' }}" data-item="other-config" href="{{ route('admin.third-party.social-login.view') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Other_Configuration') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="other-config" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (Helpers::module_permission_check('themes_and_addons') && Route::has('admin.theme.index'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('appearance') }}</span></div>
                    {{-- The mobile app's own entrance. The same engine composes both, but a
                         merchant arranging the phone app should not have to go looking for it
                         inside the website's settings. --}}
                    @if (Route::has('admin.app-builder.index'))
                        <a class="v2-nav-item {{ Request::is('admin/app-builder*') ? 'v2-is-active' : '' }}" data-item="app-builder" href="{{ route('admin.app-builder.index') }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('App_Builder') }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="app-builder" aria-label="Pin"></button></div>
                        </a>
                    @endif
                    @if (Route::has('admin.commerce.collections.index'))
                        <a class="v2-nav-item {{ Request::is('admin/commerce*') ? 'v2-is-active' : '' }}" data-item="commerce-experience" href="{{ route('admin.commerce.collections.index') }}">
                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Commerce_Experience') }}</span></span>
                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="commerce-experience" aria-label="Pin"></button></div>
                        </a>
                    @endif
                    <a class="v2-nav-item {{ (Request::is('admin/theme') || Request::is('admin/theme/*')) ? 'v2-is-active' : '' }}" data-item="theme-management" href="{{ route('admin.theme.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Theme_Management') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="theme-management" aria-label="Pin"></button></div>
                    </a>
                </div>
            @endif

            @if (count($v2ThemeRoutes) > 0)
                @php
                    $v2ThemeEnabledCount = 0;
                    foreach (($v2ThemeRoutes['route_list'] ?? []) as $themeRoute) {
                        if (isset($themeRoute['module_permission']) && Helpers::module_permission_check($themeRoute['module_permission'])) {
                            $v2ThemeEnabledCount++;
                        }
                    }
                @endphp
                @if ($v2ThemeEnabledCount > 0)
                    <div class="v2-ctx-group">
                        <div class="v2-ctx-group-head"><span>{{ $v2ThemeRoutes['name'] ?? '' }} {{ translate('Menu') }}</span></div>
                        @foreach ($v2ThemeRoutes['route_list'] as $themeRoute)
                            @if (isset($themeRoute['module_permission']) && Helpers::module_permission_check($themeRoute['module_permission']))
                                @php
                                    $v2ThemeSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower('theme-' . $themeRoute['name']));
                                    $v2ThemeActive = Request::is($themeRoute['path']) || Request::is($themeRoute['path'].'*');
                                    if (!$v2ThemeActive && !empty($themeRoute['route_list'])) {
                                        foreach ($themeRoute['route_list'] as $sub) {
                                            if (Request::is($sub['path']) || Request::is($sub['path'].'*')) { $v2ThemeActive = true; break; }
                                        }
                                    }
                                @endphp
                                @if (!empty($themeRoute['route_list']))
                                    @foreach ($themeRoute['route_list'] as $sub)
                                        @php $v2ThemeSubSlug = $v2ThemeSlug . '-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($sub['name'])); @endphp
                                        <a class="v2-nav-item {{ (Request::is($sub['path']) || Request::is($sub['path'].'*')) ? 'v2-is-active' : '' }}" data-item="{{ $v2ThemeSubSlug }}" href="{{ $sub['url'] }}">
                                            <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($sub['name']) }}</span></span>
                                            <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="{{ $v2ThemeSubSlug }}" aria-label="Pin"></button></div>
                                        </a>
                                    @endforeach
                                @else
                                    <a class="v2-nav-item {{ $v2ThemeActive ? 'v2-is-active' : '' }}" data-item="{{ $v2ThemeSlug }}" href="{{ $themeRoute['url'] }}">
                                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($themeRoute['name']) }}</span></span>
                                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="{{ $v2ThemeSlug }}" aria-label="Pin"></button></div>
                                    </a>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif

            @if (Helpers::module_permission_check('themes_and_addons'))
                <div class="v2-ctx-group">
                    <div class="v2-ctx-group-head"><span>{{ translate('Themes_&_Addons') }}</span></div>
                    {{-- Theme Setup lives inside Theme Management now (admin/theme) — one entry, one place. --}}
                    <a class="v2-nav-item {{ Request::is('admin/system-setup/addon') ? 'v2-is-active' : '' }}" data-item="system-addons" href="{{ route('admin.system-setup.addon.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('System_Addons') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="system-addons" aria-label="Pin"></button></div>
                    </a>
                    <a class="v2-nav-item {{ Request::is('admin/system-setup/addon-activation') ? 'v2-is-active' : '' }}" data-item="addon-activation" href="{{ route('admin.system-setup.addon-activation.index') }}">
                        <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate('Addon_Activation') }}</span></span>
                        <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="addon-activation" aria-label="Pin"></button></div>
                    </a>

                    @if (count($v2AddonAdminRoutes) > 0)
                        @foreach ($v2AddonAdminRoutes as $routes)
                            @foreach ($routes as $route)
                                @php $v2AddonSlug = 'addon-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($route['name'])); @endphp
                                <a class="v2-nav-item {{ strstr(Request::url(), $route['path']) ? 'v2-is-active' : '' }}" data-item="{{ $v2AddonSlug }}" href="{{ $route['url'] }}">
                                    <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($route['name']) }}</span></span>
                                    <div class="v2-nav-right"><button class="v2-pin-btn" type="button" data-pin="{{ $v2AddonSlug }}" aria-label="Pin"></button></div>
                                </a>
                            @endforeach
                        @endforeach
                    @endif
                </div>
            @endif
        </div>
    @endif

    </div>{{-- /.v2-ctx-scroll --}}

    {{-- Setup guide footer (super-admin only, always pinned at bottom when ctxpanel is visible) --}}
    @php
        $v2Setup = function_exists('checkSetupGuideRequirements') ? checkSetupGuideRequirements(panel: 'admin') : ['completePercent' => 100, 'totalSteps' => 0];
        $v2ShowSetupGuide = ($v2Setup['completePercent'] ?? 100) < 100 && auth('admin')->user()->admin_role_id == 1;
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

    {{-- The store's own release version (version.json), not the vendor platform's. --}}
    <div class="v2-version-badge" title="{{ translate('release') }} {{ getAppVersion()['released_at'] ?? '' }}">
        v{{ getAppVersion()['version'] }}
    </div>

</aside>

<aside class="v2-rail-flyout" id="v2-rail-flyout" aria-hidden="true"></aside>

{{-- Command palette (⌘K) --}}
<div class="v2-palette-scrim" id="v2-palette-scrim" hidden>
    <div class="v2-palette">
        <div class="v2-palette-head">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                 stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="9" cy="9" r="5"/><path d="m13 13 3.5 3.5"/>
            </svg>
            <input id="v2-palette-input" type="text" placeholder="{{ translate('Jump_to_anything') }} — {{ translate('orders_vendors_settings') }}…" autocomplete="off"/>
            <span class="v2-kbd">esc</span>
        </div>
        <div class="v2-palette-list" id="v2-palette-list"></div>
        <div class="v2-palette-foot">
            <span class="v2-palette-hint"><span class="v2-kbd">↑</span><span class="v2-kbd">↓</span> {{ translate('navigate') }}</span>
            <span class="v2-palette-hint"><span class="v2-kbd">↵</span> {{ translate('open') }}</span>
            <span class="v2-palette-hint"><span class="v2-kbd">esc</span> {{ translate('close') }}</span>
        </div>
    </div>
</div>
