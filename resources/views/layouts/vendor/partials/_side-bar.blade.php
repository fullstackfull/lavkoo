@php
use App\Enums\ViewPaths\Vendor\Chatting;
use App\Enums\ViewPaths\Vendor\Profile;
use App\Enums\ViewPaths\Vendor\Refund;
use App\Enums\ViewPaths\Vendor\Review;
use App\Enums\ViewPaths\Vendor\EmergencyContact;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Shop;
$shop = Shop::where(['seller_id' => auth('seller')->id()])->first();
@endphp
<div id="sidebarMain" class="d-none">
    <aside
        class="js-navbar-vertical-aside navbar navbar-vertical-aside navbar-vertical navbar-vertical-fixed navbar-expand-xl navbar-bordered">
        <div class="navbar-vertical-container">
            <div class="navbar-brand-wrapper justify-content-between side-logo dashboard-navbar-side-logo-wrapper">
                <a class="navbar-brand" href="{{ route('vendor.dashboard.index') }}" aria-label="Front">
                    @if (isset($shop))
                        <img class="navbar-brand-logo-mini for-seller-logo"
                            src="{{ getStorageImages(path: $shop->image_full_url, type: 'backend-logo') }}"
                            alt="{{ translate('logo') }}">
                    @else
                        <img class="navbar-brand-logo-mini for-seller-logo"
                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/900x400/img1.jpg') }}"
                            alt="{{ translate('logo') }}">
                    @endif
                </a>
                <button type="button"
                    class="d-none js-navbar-vertical-aside-toggle-invoker navbar-vertical-aside-toggle btn btn-icon btn-xs btn-ghost-dark">
                    <i class="tio-clear tio-lg"></i>
                </button>

                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close">
                    <i class="fi fi-rr-menu-burger"></i>
                </button>
            </div>
            <div class="navbar-vertical-footer-offset pb-0">
                <div class="navbar-vertical-content">
                    <div class="sidebar--search-form pb-3 pt-4 mx-3">
                        <div class="search--form-group">
                            <button type="button" class="btn"><i class="tio-search"></i></button>
                            <input type="text" class="js-form-search form-control form--control"
                                id="search-bar-input" placeholder="{{ translate('search_menu') . '...' }}">
                        </div>
                    </div>
                    <ul class="navbar-nav navbar-nav-lg nav-tabs">
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('vendor/dashboard*') ? 'show' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.dashboard.index') }}" title="{{ translate('dashboard') }}">
                                <i class="tio-home-vs-1-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('dashboard') }}
                                </span>
                            </a>
                        </li>
                        @php($seller = auth('seller')->user())
                        @php($sellerId = $seller['id'])
                        @php($sellerPOS = getWebConfig('seller_pos'))
                        @if ($sellerPOS == 1 && $seller['pos_status'] == 1)
                            <li
                                class="navbar-vertical-aside-has-menu {{ Request::is('vendor/pos*') ? 'active' : '' }}">
                                <a class="js-navbar-vertical-aside-menu-link nav-link"
                                    href="{{ route('vendor.pos.index') }}" title="{{ translate('POS') }}">
                                    <i class="tio-shopping nav-icon"></i>
                                    <span
                                        class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('POS') }}</span>
                                </a>
                            </li>
                        @endif

                        <li class="nav-item">
                            <small class="nav-subtitle">{{ translate('order_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('vendor/orders*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('orders') }}">
                                <i class="tio-shopping-cart nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('orders') }}
                                </span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('vendor/order*') ? 'block' : 'none' }}">
                                <li class="nav-item {{ Request::is('vendor/orders/list/all') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['all']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('all') }}
                                            <span
                                                class="badge badge-soft-info badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/pending') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['pending']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('pending') }}
                                            <span
                                                class="badge badge-soft-info badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'pending'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/confirmed') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['confirmed']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('confirmed') }}
                                            <span
                                                class="badge badge-soft-info badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'confirmed'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="nav-item {{ Request::is('vendor/orders/list/processing') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['processing']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('packaging') }}
                                            <span
                                                class="badge badge-soft-warning badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'processing'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="nav-item {{ Request::is('vendor/orders/list/out_for_delivery') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['out_for_delivery']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">
                                            {{ translate('out_for_delivery') }}
                                            <span
                                                class="badge badge-soft-warning badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'out_for_delivery'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/delivered') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['delivered']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('delivered') }}
                                            <span
                                                class="badge badge-soft-success badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'delivered'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/returned') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['returned']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('returned') }}
                                            <span class="badge badge-soft-danger badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'returned'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/failed') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['failed']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('failed To Deliver') }}
                                            <span class="badge badge-soft-danger badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'failed'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/orders/list/canceled') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.orders.list', ['canceled']) }}"
                                        title="">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('canceled') }}
                                            <span class="badge badge-soft-danger badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                {{ Order::where(['seller_is' => 'seller'])->where(['seller_id' => $sellerId])->where(['order_status' => 'canceled'])->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/refund*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('refund_Requests') }}">
                                <i class="tio-receipt-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('refund_Requests') }}
                                </span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('vendor/refund*') ? 'block' : 'none' }}">
                                <li
                                    class="nav-item {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/pending') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('vendor.refund.index', ['pending']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('pending') }}
                                            <span class="badge badge-soft-danger badge-pill ml-1">
                                                {{ RefundRequest::whereHas('order', function ($query) {
                                                    $query->where('seller_is', 'seller')->where('seller_id', auth('seller')->id());
                                                })->where('status', 'pending')->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="nav-item {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/approved') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('vendor.refund.index', ['approved']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('approved') }}
                                            <span class="badge badge-soft-info badge-pill ml-1">
                                                {{ RefundRequest::whereHas('order', function ($query) {
                                                    $query->where('seller_is', 'seller')->where('seller_id', auth('seller')->id());
                                                })->where('status', 'approved')->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/refunded') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('vendor.refund.index', ['refunded']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('refunded') }}
                                            <span class="badge badge-soft-success badge-pill ml-1">
                                                {{ RefundRequest::whereHas('order', function ($query) {
    $query->where('seller_is', 'seller')->where('seller_id', auth('seller')->id());
})->where('status', 'refunded')->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/refund/' . Refund::INDEX[URI] . '/rejected') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('vendor.refund.index', ['rejected']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">
                                            {{ translate('rejected') }}
                                            <span class="badge badge-danger badge-pill ml-1">
                                                {{ RefundRequest::whereHas('order', function ($query) {
    $query->where('seller_is', 'seller')->where('seller_id', auth('seller')->id());
})->where('status', 'rejected')->count() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <small class="nav-subtitle">{{ translate('product_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/product*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('products') }}">
                                <i class="tio-premium-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('products') }}
                                </span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('vendor/products*') ? 'block' : '' }}">
                                <li
                                    class="nav-item {{ Request::is('vendor/products/list/all') || Request::is('vendor/products/update*') || Request::is('vendor/products/view*') ? 'active' : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('vendor.products.list', ['type' => 'all']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('product_list') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/list/approved') ? 'active' : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('vendor.products.list', ['type' => 'approved']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('approved_product_list') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/list/new-request') ? 'active' : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('vendor.products.list', ['type' => 'new-request']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('new_product_request') }}</span>
                                    </a>
                                </li>
                                <li class="nav-item {{ Request::is('vendor/products/list/denied') ? 'active' : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('vendor.products.list', ['type' => 'denied']) }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('denied_product_request') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/add') || (Request::is('vendor/products/update/*') && request()->has('product-gallery')) ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.products.add') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">
                                            {{ translate('add_new_product') }}
                                        </span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/product-gallery') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.products.product-gallery') }}"
                                        title="{{ translate('product_gallery') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('product_gallery') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/stock-limit-list') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.products.stock-limit-list') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">
                                            {{ translate('Limited_Stocks') }}
                                        </span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/bulk-import') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.products.bulk-import') }}"
                                        title="{{ translate('bulk_import') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{ translate('bulk_import') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/products/request-restock-list') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.products.request-restock-list') }}"
                                        title="{{ translate('Request_Restock_List') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{ translate('Request_Restock_List') }}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/reviews/' . Review::INDEX[URI] . '*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.reviews.index') }}"
                                title="{{ translate('product_Reviews') }}">
                                <i class="tio-star nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('product_Reviews') }}
                                </span>
                            </a>
                        </li>


                            @if(getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status') == 1)
                            <li class="nav-item">
                                <small class="nav-subtitle">{{ translate('Auction_Management') }}</small>
                                <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                            </li>

                                <?php
                                $auctionRoutes = include(base_path("Modules/Auction/Addon/vendor_routes.php"));
                                ?>
                                @foreach($auctionRoutes as $auctionRoute)
                                    <li class="navbar-vertical-aside-has-menu
                                        @foreach($auctionRoute['routes'] as $route)
                                                    {{ strstr(Request::url(), $route['path']) ? 'active' : '' }}
                                        @endforeach">
                                        <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:" title="{{ translate($auctionRoute['name']) }}">
                                            <i class="tio-chart-bar-1 nav-icon"></i>
                                            <span
                                                class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            <span class="text-truncate">
                                                {{ translate($auctionRoute['name']) }}
                                            </span>
                                        </span>
                                        </a>

                                        <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                            style="display:
                        @foreach($auctionRoute['routes'] as $route)
                                            {{ strstr(Request::url(), $route['path']) ? 'block' : '' }}
                        @endforeach">
                                            @foreach($auctionRoute['routes'] as $route)
                                                <?php
                                                    $badgeData = getAuctionSidebarBadgeData($route['path'], $sidebarAuctionVendorOwnCounts ?? []);
                                                ?>

                                                @if((str_contains($route['path'], 'vendor/auction/products/add') && getWebConfig(name: 'active_auction_for_vendor') == 1) || !str_contains($route['path'], 'vendor/auction/products/add'))
                                                    <li class="nav-item {{strstr(Request::url(), $route['path']) ? 'active' : '' }}">
                                                    <a class="nav-link"
                                                       href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                                    <span class="tio-circle nav-indicator-icon"></span>
                                                    <span class="text-truncate">
                                                        {{ translate($route['name']) }}
                                                        @if($badgeData['is_status_route'])
                                                            <span class="badge badge-soft-{{ $badgeData['badge_class'] }} badge-pill {{ Session::get('direction') === 'rtl' ? 'mr-1' : 'ml-1' }}">
                                                                {{ $badgeData['count'] }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                    </a>
                                                </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </li>
                                @endforeach
                            @endif

                        <li class="nav-item">
                            <small class="nav-subtitle">{{ translate('promotion_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/coupon*') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('vendor.coupon.index') }}"
                                title="{{ translate('coupons') }}">
                                <i class="tio-users-switch nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('coupons') }}
                                </span>
                            </a>
                        </li>

                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/clearance-sale*') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('vendor.clearance-sale.index') }}"
                                title="{{ translate('Clearance_Sale') }}">
                                <i class="tio-notice nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('Clearance_Sale') }}
                                </span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <small class="nav-subtitle">{{ translate('help_&_support') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/messages*') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('vendor.messages.index', ['type' => 'customer']) }}"
                                title="{{ translate('inbox') }}">
                                <i class="tio-chat nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('inbox') }}
                                </span>
                            </a>
                        </li>
                        <li
                            class="nav-item {{ Request::is('vendor/transaction/order-list') ? 'scroll-here' : '' }}">
                            <small class="nav-subtitle">{{ translate('reports_&_analytics') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/transaction/order-list') || Request::is('vendor/transaction/expense-list') || Request::is('vendor/transaction/order-history-log*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.transaction.order-list') }}"
                                title="{{ translate('transactions_Report') }}">
                                <i class="tio-chart-bar-3 nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate text-capitalize">
                                    {{ translate('transactions_Report') }}
                                </span>
                            </a>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/report/all-product') || Request::is('vendor/report/stock-product-report') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link text-capitalize"
                                href="{{ route('vendor.report.all-product') }}"
                                title="{{ translate('product_report') }}">
                                <i class="tio-chart-bar-4 nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    <span class="position-relative text-capitalize">
                                        {{ translate('product_report') }}
                                    </span>
                                </span>
                            </a>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/report/order-report') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link text-capitalize"
                                href="{{ route('vendor.report.order-report') }}"
                                title="{{ translate('order_report') }}">
                                <i class="tio-chart-bar-1 nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('order_Report') }}
                                </span>
                            </a>
                        </li>

                        @if (getCheckAddonPublishedStatus(moduleName: 'TaxModule'))
                            @foreach (include base_path('Modules/TaxModule/Addon/vendor_tax_report_routes.php') as $route)
                                <li
                                    class="navbar-vertical-aside-has-menu
                                    {{ strstr(Request::url(), $route['path']) ? 'active' : '' }}

                                    @if (isset($route['sub_routes'])) @foreach ($route['sub_routes'] as $subRoute)
                                            {{ strstr(Request::url(), $subRoute['path']) ? 'active' : '' }}
                                        @endforeach @endif
                                ">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link text-capitalize"
                                        href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                        <i class="tio-chart-bar-1 nav-icon"></i>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate($route['name']) }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        @endif

                        <li class="nav-item {{ Request::is('vendor/business-settings*') ? 'scroll-here' : '' }}">
                            <small class="nav-subtitle" title="">{{ translate('business_section') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/business-settings/shipping-method*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.business-settings.shipping-method.index') }}"
                                title="{{ translate('shipping_methods') }}">
                                <i class="tio-settings nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate text-capitalize text-capitalize">
                                    {{ translate('shipping_methods') }}
                                </span>
                            </a>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/business-settings/withdraw*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.business-settings.withdraw.index') }}"
                                title="{{ translate('withdraws') }}">
                                <i class="tio-wallet-outlined nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate text-capitalize">
                                    {{ translate('withdraws') }}
                                </span>
                            </a>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/profile/' . Profile::INDEX[URI]) || Request::is('vendor/profile/' . Profile::BANK_INFO_UPDATE[URI]) ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.profile.index') }}"
                                title="{{ translate('bank_Information') }}">
                                <i class="tio-shop nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate text-capitalize">
                                    {{ translate('bank_Information') }}
                                </span>
                            </a>
                        </li>
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('vendor/shop*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('vendor.shop.index') }}" title="{{ translate('shop_Settings') }}">
                                <i class="tio-home nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate text-capitalize">
                                    {{ translate('shop_Settings') }}
                                </span>
                            </a>
                        </li>

                        <li class="nav-item {{ Request::is('vendor/delivery-man*') ? 'scroll-here' : '' }}">
                            <small class="nav-subtitle">{{ translate('delivery_man_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('vendor/delivery-man*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:">
                                <i class="tio-user nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('delivery-Man') }}
                                </span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('vendor/delivery-man*') ? 'block' : 'none' }}">
                                <li class="nav-item {{ Request::is('vendor/delivery-man/index') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.delivery-man.index') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">{{ translate('add_new') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/delivery-man/list') || Request::is('vendor/delivery-man/update') || Request::is('vendor/delivery-man/rating/*') || Request::is('vendor/delivery-man/wallet*') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('vendor.delivery-man.list') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{ translate('list') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('vendor/delivery-man/withdraw/*') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.delivery-man.withdraw.index') }}"
                                        title="{{ translate('withdraws') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{ translate('withdraws') }}</span>
                                    </a>
                                </li>

                                <li
                                    class="nav-item {{ Request::is('vendor/delivery-man/emergency-contact/*') ? 'active' : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('vendor.delivery-man.emergency-contact.index') }}"
                                        title="{{ translate('withdraws') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('emergency_contact') }}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <?php $checkSetupGuideRequirements = checkSetupGuideRequirements(panel: 'vendor'); ?>

                        <li class="nav-item ">

                            @if ($checkSetupGuideRequirements['completePercent'] < 100)
                        <li class="pb-5">
                            <div class="py-5"></div>
                        </li>
                    @else
                        <li>
                            <div class="py-2"></div>
                        </li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    </aside>
</div>

@include('layouts.vendor.partials._setup-guide')
