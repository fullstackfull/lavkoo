@php
    use App\Utils\Helpers;
    use App\Enums\EmailTemplateKey;
    $eCommerceLogo = getWebConfig(name: 'company_web_logo');
@endphp

<aside class="js-aside aside d-none d-lg-block">
    <div class="aside-header d-flex align-items-center gap-2 justify-content-between">
        <a class="navbar-logo" href="{{ route('admin.dashboard.index') }}">
            <img height="24" src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}"
                 alt="{{ translate('logo') }}">
        </a>
        <button type="button" class="js-aside-toggle navbar-aside-toggle btn-icon border-0">
            <i class="fi fi-rr-menu-burger"></i>
        </button>
    </div>
    <div class="aside-body search-aside-attribute-container py-4 pt-0">
        <div class="aside-search-form pt-lg-3 pb-3">
            <div class="input-group flex-nowrap">
                <input type="text" class="form-control search-aside-attribute"
                       placeholder="{{ translate('search_menu') }}">
                <span class="input-group-text"><i class="fi fi-rr-search"></i></span>
            </div>
        </div>

        <ul class="aside-nav navbar-nav gap-2">
            <li>
                <a class="nav-link {{ Request::is('admin/dashboard') ? 'active' : '' }}"
                   title="{{ translate('dashboard') }}" href="{{ route('admin.dashboard.index') }}">
                    <i class="fi fi-sr-home"></i>
                    <span class="aside-mini-hidden-element text-truncate">
                        {{ translate('dashboard') }}
                    </span>
                </a>
            </li>
            @if (Helpers::module_permission_check('pos'))
                <li>
                    <a class="nav-link {{ Request::is('admin/pos*') ? 'active' : '' }}" title="{{ translate('POS') }}"
                       href="{{ route('admin.pos.index') }}">
                        <i class="fi fi-sr-point-of-sale-bill"></i>
                        <span class="aside-mini-hidden-element text-truncate">{{ translate('POS') }}</span>
                    </a>
                </li>
            @endif
            @if(Helpers::module_permission_check('orders'))
                <li class="nav-item nav-item_title {{ Request::is('admin/orders*')?((Request::is('admin/orders/details/*') && request()->has('vendor-order-list')) ? '' : 'scroll-here'):''}}">
                    <small class="nav-subtitle" title="">{{ translate('order_management') }}</small>
                </li>
                <li class="{{ Request::is('admin/orders*') ? 'sub-menu-opened' : ''}}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/orders*')?((Request::is('admin/orders/details/*') && request()->has('vendor-order-list')) ? '' : 'active'):''}}"
                       href="javascript:" title="{{ translate('orders') }}">
                        <i class="fi fi-sr-shopping-cart"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">{{ translate('orders') }}</span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('orders') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/all') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list', ['all']) }}" title="{{ translate('all') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('all') }}
                                </span>
                                <span class="badge fw-bold badge-info badge-sm text-bg-info">
                                    {{ $sidebarTotalOrders }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/pending') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['pending']) }}" title="{{ translate('pending') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('pending') }}
                                </span>
                                <span class="badge fw-bold badge-info badge-sm text-bg-info">
                                  {{ $sidebarOrderCounts['pending'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/confirmed') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['confirmed']) }}"
                               title="{{ translate('confirmed') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('confirmed') }}
                                </span>
                                <span class="badge fw-bold badge-success badge-sm text-bg-success">
                                  {{ $sidebarOrderCounts['confirmed'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/processing') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['processing']) }}"
                               title="{{ translate('packaging') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('packaging') }}
                                </span>
                                <span class="badge fw-bold badge-warning badge-sm text-bg-warning">
                                 {{ $sidebarOrderCounts['processing'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/out_for_delivery') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['out_for_delivery']) }}"
                               title="{{ translate('out_for_delivery') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('out_for_delivery') }}
                                </span>
                                <span class="badge fw-bold badge-warning badge-sm text-bg-warning">
                                   {{ $sidebarOrderCounts['out_for_delivery'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/delivered') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['delivered']) }}"
                               title="{{ translate('delivered') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('delivered') }}
                                </span>
                                <span class="badge fw-bold badge-success badge-sm text-bg-success">
                                  {{ $sidebarOrderCounts['delivered'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/returned') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['returned']) }}" title="{{ translate('returned') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('returned') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                 {{ $sidebarOrderCounts['returned'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/failed') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['failed']) }}" title="{{ translate('failed') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('failed_to_Deliver') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                  {{ $sidebarOrderCounts['failed'] ?? 0 }}
                                </span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/orders/list/canceled') ? 'active' : '' }}"
                               href="{{ route('admin.orders.list',['canceled']) }}" title="{{ translate('canceled') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('canceled') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                {{ $sidebarOrderCounts['canceled'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="{{ Request::is('admin/refund-section/*') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/refund-section/refund/*') ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('refund_Requests') }}">
                        <i class="fi fi-sr-refund-alt"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('refund_Requests') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('refund_Requests') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/refund-section/refund/list/pending') ? 'active' : '' }}"
                               href="{{ route('admin.refund-section.refund.list',['pending']) }}"
                               title="{{ translate('pending') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('pending') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                 {{ $sidebarRefundCounts['pending'] ?? 0 }}
                                </span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/refund-section/refund/list/approved') ? 'active' : '' }}"
                               href="{{ route('admin.refund-section.refund.list',['approved']) }}"
                               title="{{ translate('approved') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('approved') }}
                                </span>
                                <span class="badge fw-bold badge-info badge-sm text-bg-info">
                                    {{ $sidebarRefundCounts['approved'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/refund-section/refund/list/refunded') ? 'active' : '' }}"
                               href="{{ route('admin.refund-section.refund.list',['refunded']) }}"
                               title="{{ translate('refunded') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('refunded') }}
                                </span>
                                <span class="badge fw-bold badge-success badge-sm text-bg-success">
                                  {{ $sidebarRefundCounts['refunded'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/refund-section/refund/list/rejected') ? 'active' : '' }}"
                               href="{{ route('admin.refund-section.refund.list',['rejected']) }}"
                               title="{{ translate('rejected') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('rejected') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                 {{ $sidebarRefundCounts['rejected'] ?? 0 }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>
            @endif
            @if(Helpers::module_permission_check('catalog'))
                <li class="nav-item nav-item_title {{ (Request::is('admin/brand*') || Request::is('admin/category*') || Request::is('admin/sub*') || Request::is('admin/attribute*') || Request::is('admin/products*')) ? 'scroll-here' : '' }}">
                    <small class="nav-subtitle" title="">{{ translate('product_management') }}</small>
                </li>
                <li class="{{ (Request::is('admin/category*') || Request::is('admin/sub-category*') || Request::is('admin/sub-sub-category*'))  ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ (Request::is('admin/category*') || Request::is('admin/sub-category*') || Request::is('admin/sub-sub-category*')) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('category_Setup') }}">
                        <i class="fi fi-sr-apps"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('category_Setup') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">
                            {{ translate('category_Setup') }}
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/category/*') ? 'active' : '' }}"
                               href="{{ route('admin.category.view') }}" title="{{ translate('categories') }}">
                                <span class="text-truncate">{{ translate('categories') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/sub-category/*') ? 'active' : '' }}"
                               href="{{ route('admin.sub-category.view') }}" title="{{ translate('sub_Categories') }}">
                                <span class="text-truncate">{{ translate('sub_Categories') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/sub-sub-category/*') ? 'active' : '' }}"
                               href="{{ route('admin.sub-sub-category.view') }}"
                               title="{{ translate('sub_Sub_Categories') }}">
                                <span class="text-truncate">{{ translate('sub_Sub_Categories') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/brand/list') ? 'active' : '' }}"
                       href="{{ route('admin.brand.list') }}" title="{{ translate('brand_setup') }}">
                        <i class="fi fi-sr-brand"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center text-truncate max-w-180">
                            {{ translate('Brand_Setup') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/attribute*') ? 'active' : '' }}"
                       href="{{ route('admin.attribute.view') }}" title="{{ translate('product_Attribute_Setup') }}">
                        <i class="fi fi-sr-sitemap"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center text-truncate max-w-180">
                            {{ translate('product_Attribute_Setup') }}
                        </span>
                    </a>
                </li>
                <li class="{{ (Request::is('admin/products/list/in-house*') || Request::is('admin/products/bulk-import') || Request::is('admin/products/request-restock-list')  || (Request::is('admin/products/add')) || (Request::is('admin/products/view/in-house/*')) || (Request::is('admin/products/barcode/*'))|| (Request::is('admin/products/stock-limit-list/in_house')) || (Request::is('admin/products/update/*') || request()->has('product-gallery'))) ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ (Request::is('admin/products/list/in-house*') || Request::is('admin/products/bulk-import') || Request::is('admin/products/request-restock-list')  || (Request::is('admin/products/add')) || (Request::is('admin/products/view/in-house/*')) || (Request::is('admin/products/barcode/*'))|| Request::is('admin/products/stock-limit-list/in_house') || (Request::is('admin/products/update/*') || request()->has('product-gallery'))) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('In_House_Products') }}">
                        <i class="fi fi-sr-box-open"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('In_House_Products') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('In_House_Products') }}</li>
                        <li class="nav-item ">
                            <a class="nav-link {{ (Request::is('admin/products/list/in-house*') || (Request::is('admin/products/view/in-house/*')) || (Request::is('admin/products/stock-limit-list/in-house*')) || (Request::is('admin/products/barcode/*'))) ? 'active' : '' }}"
                               href="{{ route('admin.products.list', ['in-house']) }}"
                               title="{{ translate('Product_List') }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('Product_List') }}
                                </span>
                                <span class="badge fw-bold badge-success badge-sm text-bg-success">
                                    {{getAdminProductsCount('all') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/products/add') || (Request::is('admin/products/update/*') || request()->has('product-gallery')) ? 'active' : '' }}"
                               href="{{ route('admin.products.add') }}" title="{{ translate('add_New_Product') }}">
                                <span class="text-truncate">{{ translate('add_New_Product') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/products/stock-limit-list/in_house') ? 'active' : '' }}"
                               href="{{ route('admin.products.stock-limit-list', ['in_house']) }}" title="{{ translate('Limited_stock') }}">
                                <span class="text-truncate">{{ translate('Limited_Stock') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/products/bulk-import') ? 'active' : '' }}"
                               href="{{ route('admin.products.bulk-import') }}" title="{{ translate('bulk_import') }}">
                                <span class="text-truncate">{{ translate('Bulk_Import') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/products/request-restock-list') ? 'active' : '' }}"
                               href="{{ route('admin.products.request-restock-list') }}"
                               title="{{ translate('Request_Restock_List') }}">
                                <span class="text-truncate">{{ translate('Request_Restock_List') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="{{ Request::is('admin/products/list/vendor*')||(Request::is('admin/products/view/vendor/*'))||Request::is('admin/products/updated-product-list') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/products/list/vendor*')||(Request::is('admin/products/view/vendor/*'))||Request::is('admin/products/updated-product-list') ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('vendor_Products') }}">
                        <i class="fi fi-sr-seller"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('vendor_Products') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('vendor_Products') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{str_contains(url()->current().'?request_status='.request()->get('request_status'),'admin/products/list/vendor?request_status=0') == 1 ? 'active' : '' }}"
                               title="{{ translate('new_Products_Requests') }}"
                               href="{{ route('admin.products.list',['vendor', 'request_status'=>'0']) }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ Str::limit(translate('new_Products_Requests'), 18, '...') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                    {{ getVendorProductsCount(type: 'new-product', panel: 'admin') }}
                                </span>
                            </a>
                        </li>
                        @if (getWebConfig(name: 'product_wise_shipping_cost_approval')==1)
                            <li class="nav-item">
                                <a class="nav-link text-capitalize {{ Request::is('admin/products/updated-product-list/vendor') ? 'active' : '' }}"
                                   title="{{ translate('product_update_requests') }}"
                                   href="{{ route('admin.products.updated-product-list',['type' => 'vendor']) }}">
                                    <span class="flex-grow-1 text-truncate">
                                        {{Str::limit(translate('product_update_requests'), 18, '...') }}
                                    </span>
                                    <span class="badge fw-bold badge-info badge-sm text-bg-info">
                                        {{getVendorProductsCount(type: 'product-updated-request', panel: 'admin') }}
                                    </span>
                                </a>
                            </li>
                        @endif
                        <li class="nav-item">
                            <a class="nav-link {{str_contains(url()->current().'?request_status='.request()->get('request_status'),'/admin/products/list/vendor?request_status=1')==1? 'active' : '' }}"
                               title="{{ translate('approved_Products') }}"
                               href="{{ route('admin.products.list',['vendor', 'request_status'=>'1']) }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('approved_Products') }}
                                </span>
                                <span class="badge fw-bold badge-success badge-sm text-bg-success">
                                    {{getVendorProductsCount(type: 'approved', panel: 'admin') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{str_contains(url()->current().'?request_status='.request()->get('request_status'),'/admin/products/list/vendor?request_status=2')==1? 'active' : '' }}"
                               title="{{ translate('denied_Products') }}"
                               href="{{ route('admin.products.list',['vendor', 'request_status'=>'2']) }}">
                                <span class="flex-grow-1 text-truncate">
                                    {{ translate('denied_Products') }}
                                </span>
                                <span class="badge fw-bold badge-danger badge-sm text-bg-danger">
                                    {{getVendorProductsCount(type: 'denied', panel: 'admin') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>
                <li>
                    <a class="nav-link {{ Request::is('admin/products/product-gallery') ? 'active' : '' }}"
                       href="{{ route('admin.products.product-gallery') }}" title="{{ translate('Product_Gallery') }}">
                        <i class="fi fi-sr-boxes"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            {{ translate('Product_Gallery') }}
                        </span>
                    </a>
                </li>
            @endif

            @if(Helpers::module_permission_check('marketing'))
                <li class="nav-item nav-item_title {{ (Request::is('admin/banner*') || (Request::is('admin/coupon*')) || (Request::is('admin/notification*')) || (Request::is('admin/deal*'))) ? 'scroll-here' : '' }}">
                    <small class="nav-subtitle" title="">{{ translate('promotion_management') }}</small>
                </li>
                <li>
                    <a class="nav-link {{ Request::is('admin/banner*') ? 'active' : '' }}"
                       href="{{ route('admin.banner.list') }}" title="{{ translate('banner_Setup') }}">
                        <i class="fi fi-sr-pennant"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('banner_Setup') }}
                        </span>
                    </a>
                </li>

                <li class="{{ (Request::is('admin/coupon*') || Request::is('admin/deal*')) ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ (Request::is('admin/coupon*') || Request::is('admin/deal*')) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('offers_&_Deals') }}">
                        <i class="fi fi-sr-badge-percent"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('offers_&_Deals') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('offers_&_Deals') }}</li>
                        <li>
                            <a class="nav-link {{ Request::is('admin/coupon*') ? 'active' : '' }}"
                               href="{{ route('admin.coupon.add') }}" title="{{ translate('coupon') }}">
                                <span class="text-truncate">{{ translate('coupon') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link {{ (Request::is('admin/deal/flash') || Request::is('admin/deal/flash-add') || (Request::is('admin/deal/update*'))) ? 'active' : '' }}"
                               href="{{ route('admin.deal.flash') }}" title="{{ translate('flash_Deals') }}">
                                <span class="text-truncate">{{ translate('flash_Deals') }}</span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link {{ (Request::is('admin/deal/day') || (Request::is('admin/deal/day-update*'))) ? 'active' : '' }}"
                               href="{{ route('admin.deal.day') }}" title="{{ translate('deal_of_the_day') }}">
                                <span class="text-truncate">
                                    {{ translate('deal_of_the_day') }}
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link {{ (Request::is('admin/deal/feature') || Request::is('admin/deal/feature/new') || Request::is('admin/deal/feature-update*')) ? 'active' : '' }}"
                               href="{{ route('admin.deal.feature') }}" title="{{ translate('featured_Deal') }}">
                                <span class="text-truncate">
                                    {{ translate('featured_Deal') }}
                                </span>
                            </a>
                        </li>

                        <li>
                            <a class="nav-link {{ Request::is('admin/deal/clearance-sale') || Request::is('admin/deal/clearance-sale*') ? 'active' : '' }}"
                               href="{{ route('admin.deal.clearance-sale.index') }}"
                               title="{{ translate('Clearance_Sale') }}">
                                <span class="text-truncate">
                                    {{ translate('Clearance_Sale') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="{{ Request::is('admin/notification*') || Request::is('admin/push-notification/index*') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/notification*') || Request::is('admin/push-notification/index*') ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('notifications') }}">
                        <i class="fi fi-sr-paper-plane"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('notifications') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('notifications') }}</li>
                        <li>
                            <a class="nav-link {{!Request::is('admin/notification/push') && Request::is('admin/notification/*') ? 'active' : '' }}"
                               href="{{ route('admin.notification.index') }}"
                               title="{{ translate('send_notification') }}">
                                <span class="text-truncate text-capitalize">
                                    {{ translate('send_notification') }}
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link text-capitalize {{ Request::is('admin/push-notification/index*') ? 'active' : '' }}"
                               href="{{ route('admin.push-notification.index') }}"
                               title="{{ translate('push_notifications_setup') }}">
                                <span class="text-truncate text-capitalize">
                                    {{ translate('push_notifications_setup') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="{{ Request::is('admin/business-settings/announcement*') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/business-settings/announcement*') ? 'active' : '' }}"
                       href="{{ route('admin.business-settings.announcement') }}"
                       title="{{ translate('announcement') }}">
                        <i class="fi fi-sr-megaphone-sound-waves"></i>
                        <span class="aside-mini-hidden-element text-truncate max-w-180"> {{ translate('announcement') }} </span>
                    </a>
                </li>
            @endif

            @php($getEnabledThemeRoutes=0)
            @if (count(config('get_theme_routes')) > 0)
                @foreach (config('get_theme_routes')['route_list'] as $route)
                    @if(isset($route['module_permission']) && Helpers::module_permission_check($route['module_permission']))
                        @php($getEnabledThemeRoutes++)
                    @endif
                @endforeach
            @endif

            @if($getEnabledThemeRoutes > 0)
                @if (count(config('get_theme_routes')) > 0)
                    <li class="nav-item nav-item_title {{ (Request::is('admin/banner*') || (Request::is('admin/coupon*')) || (Request::is('admin/notification*')) || (Request::is('admin/deal*'))) ? 'scroll-here' : '' }}">
                        <small class="nav-subtitle" title="">
                            {{ config('get_theme_routes')['name'] }} {{ translate('Menu') }}
                        </small>
                    </li>
                    @foreach (config('get_theme_routes')['route_list'] as $route)
                        @if(isset($route['module_permission']) && Helpers::module_permission_check($route['module_permission']))
                            <li class="{{ (Request::is($route['path']) || Request::is($route['path'].'*')) ? 'active' : '' }} @foreach ($route['route_list'] as $sub_route){{ (Request::is($sub_route['path']) || Request::is($sub_route['path'].'*')) ? 'active' : '' }}@endforeach">
                                <a class="nav-link {{ count($route['route_list']) > 0 ? 'nav-link-toggle':'' }}"
                                   href="{{ count($route['route_list']) > 0 ? 'javascript:':$route['url'] }}"
                                   title="{{ translate($route['name']) }}">
                                    {!! $route['icon'] !!}
                                    <span
                                        class="aside-mini-hidden-element text-truncate">{{translate($route['name']) }}</span>
                                </a>

                                @if (count($route['route_list']) > 0)
                                    <ul class="aside-submenu navbar-nav">
                                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('system_settings') }}</li>
                                        @foreach ($route['route_list'] as $sub_route)
                                            <li class="{{ (Request::is($sub_route['path']) || Request::is($sub_route['path'].'*')) ? 'active' : '' }}">
                                                <a class="nav-link" href="{{$sub_route['url']}}"
                                                   title="{{ translate($sub_route['name']) }}">
                                                    <span
                                                        class="text-truncate">{{ translate($sub_route['name']) }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endif
                    @endforeach
                @endif
            @endif

            @if(Helpers::module_permission_check('people'))
                <li class="nav-item nav-item_title {{ (Request::is('admin/support-ticket*') || Request::is('admin/contact*')) ? 'scroll-here' : '' }}">
                    <small class="nav-subtitle" title="">{{ translate('help_&_support') }}</small>
                </li>
                <li>
                    <a class="nav-link {{ Request::is('admin/messages*') ? 'active' : '' }}"
                       title="{{ translate('inbox') }}"
                       href="{{ route('admin.messages.index', ['type' => 'customer']) }}">
                        <i class="fi fi-sr-envelope"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            {{ translate('inbox') }}
                        </span>
                    </a>
                </li>
                <li>
                    <a class="nav-link {{ Request::is('admin/contact*') ? 'active' : '' }}"
                       href="{{ route('admin.contact.list') }}" title="{{ translate('messages') }}">
                        <i class="fi fi-sr-comment-alt-dots"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            <span class="position-relative">
                                {{ translate('messages') }}
                                @if($sidebarUnseenMessages != 0)
                                    <span
                                        class="btn-status btn-xs-status btn-status-danger position-absolute top-0 menu-status"></span>
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
                <li>
                    <a class="nav-link {{ Request::is('admin/support-ticket*') ? 'active' : '' }}"
                       href="{{ route('admin.support-ticket.view') }}" title="{{ translate('support_Ticket') }}">
                        <i class="fi fi-sr-headphones"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            <span class="position-relative">
                                {{ translate('support_Ticket') }}
                            </span>
                        </span>
                    </a>
                </li>
            @endif

            @if(Helpers::module_permission_check('reports'))
                <li class="nav-item nav-item_title
                {{ (Request::is('admin/report/earning') ||
                    Request::is('admin/report/inhouse-product-sale') ||
                    Request::is('admin/report/vendor-report') ||
                    Request::is('admin/report/earning') ||
                    Request::is('admin/transaction/list') ||
                    Request::is('admin/refund-section/refund-list') ||
                    Request::is('admin/stock/product-in-wishlist') ||
                    Request::is('admin/reviews*') ||
                    Request::is('admin/stock/product-stock') ||
                    Request::is('admin/transaction/wallet-bonus') ||
                    Request::is('admin/report/order')) ? 'scroll-here' : ''
                    }}

                @if(getCheckAddonPublishedStatus(moduleName: 'TaxModule'))
                    @foreach(include(base_path("Modules/TaxModule/Addon/tax_report_routes.php")) as $route)
                        {{ strstr(Request::url(), $route['path']) ? 'scroll-here' : '' }}

                        @if(isset($route['sub_routes']))
                            @foreach($route['sub_routes'] as $subRoute)
                                {{ strstr(Request::url(), $subRoute['path']) ? 'scroll-here' : '' }}
                            @endforeach
                        @endif
                    @endforeach
                @endif
                ">
                    <small class="nav-subtitle" title="">
                        {{ translate('reports_&_Analysis') }}
                    </small>
                </li>

                <li class="{{ (Request::is('admin/report/admin-earning') || Request::is('admin/report/vendor-earning') || Request::is('admin/report/inhouse-product-sale') || Request::is('admin/report/vendor-report') || Request::is('admin/report/earning') || Request::is('admin/transaction/order-transaction-list') || Request::is('admin/transaction/expense-transaction-list') || Request::is('admin/report/transaction/refund-transaction-list') || Request::is('admin/transaction/wallet-bonus')) ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ (Request::is('admin/report/admin-earning') || Request::is('admin/report/vendor-earning') || Request::is('admin/report/inhouse-product-sale') || Request::is('admin/report/vendor-report') || Request::is('admin/report/earning') || Request::is('admin/transaction/order-transaction-list') || Request::is('admin/transaction/expense-transaction-list') || Request::is('admin/report/transaction/refund-transaction-list') || Request::is('admin/transaction/wallet-bonus')) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('sales_&_Transaction_Report') }}">
                        <i class="fi fi-sr-stats"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('sales_&_Transaction_Report') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('sales_&_Transaction_Report') }}</li>
                        <li>
                            <a class="nav-link {{ (Request::is('admin/report/admin-earning') || Request::is('admin/report/vendor-earning')) ? 'active' : '' }}"
                               href="{{ route('admin.report.admin-earning') }}"
                               title="{{ translate('Earning_Reports') }}">
                                <span class="text-truncate">
                                    {{ translate('Earning_Reports') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/report/inhouse-product-sale') ? 'active' : '' }}"
                               href="{{ route('admin.report.inhouse-product-sale') }}"
                               title="{{ translate('inhouse_Sales') }}">
                                <span class="text-truncate">
                                    {{ translate('inhouse_Sales') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/report/vendor-report') ? 'active' : '' }}"
                               href="{{ route('admin.report.vendor-report') }}" title="{{ translate('vendor_Sales') }}">
                                <span class="text-truncate text-capitalize">
                                    {{ translate('vendor_Sales') }}
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link {{ (Request::is('admin/transaction/order-transaction-list') || Request::is('admin/transaction/expense-transaction-list') || Request::is('admin/transaction/refund-transaction-list') || Request::is('admin/report/transaction/refund-transaction-list') || Request::is('admin/transaction/wallet-bonus')) ? 'active' : '' }}"
                               href="{{ route('admin.transaction.order-transaction-list') }}"
                               title="{{ translate('transaction_Report') }}">
                                <span class="text-truncate">
                                    {{ translate('transaction_Report') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li>
                    <a class="nav-link {{ (Request::is('admin/report/all-product') || Request::is('admin/stock/product-in-wishlist') || Request::is('admin/stock/product-stock')) ? 'active' : '' }}"
                       href="{{ route('admin.report.all-product') }}" title="{{ translate('product_Report') }}">
                        <i class="fi fi-sr-stats"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            <span class="position-relative">
                                {{ translate('product_Report') }}
                            </span>
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/report/order') ? 'active' : '' }}"
                       href="{{ route('admin.report.order') }}" title="{{ translate('order_Report') }}">
                        <i class="fi fi-sr-rectangle-list"></i>
                        <span class="aside-mini-hidden-element text-truncate">
                            {{ translate('order_Report') }}
                        </span>
                    </a>
                </li>

                @if(getCheckAddonPublishedStatus(moduleName: 'TaxModule'))
                    @foreach(include(base_path("Modules/TaxModule/Addon/tax_report_routes.php")) as $route)
                        <li>
                            <a class="nav-link
                            {{ strstr(Request::url(), $route['path']) ? 'active' : '' }}

                            @if(isset($route['sub_routes']))
                                @foreach($route['sub_routes'] as $subRoute)
                                    {{ strstr(Request::url(), $subRoute['path']) ? 'active' : '' }}
                                @endforeach
                            @endif
                            "
                               href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                <i class="fi fi-sr-rectangle-list"></i>
                                <span class="aside-mini-hidden-element text-truncate">
                                     {{ translate($route['name']) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                @endif
            @endif

            @if (Helpers::module_permission_check('marketing'))
                @if(Route::has('admin.blog.view'))
                    <li class="nav-item nav-item_title {{ Request::is('admin/blog*') ? 'scroll-here' : '' }}">
                        <small class="nav-subtitle" title="">
                            {{ translate('Blog_management') }}
                        </small>
                    </li>

                    <li class="{{ Request::is('admin/blog/*') ? 'sub-menu-opened' : '' }}">
                        <a class="nav-link nav-link-toggle {{ Request::is('admin/blog*') ? 'active' : '' }}"
                           href="javascript:" title="{{ translate('blog') }}">
                            <i class="fi fi-sr-layout-fluid"></i>
                            <span
                                class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                                <span class="text-truncate max-w-180">
                                    {{ translate('blog') }}
                                </span>
                                <i class="fi fi-sr-angle-down"></i>
                            </span>
                        </a>
                        <ul class="aside-submenu navbar-nav">
                            <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('blog') }}</li>
                            <li class="nav-item" title="{{ translate('add_new') }}">
                                <a class="nav-link {{ Request::is('admin/blog/add') ? 'active' : '' }}"
                                   href="{{ route('admin.blog.add') }}">
                                    <span class="text-truncate">{{ translate('add_new') }}</span>
                                </a>
                            </li>
                            <li class="nav-item "
                                title="{{ translate('list') }}">
                                <a class="nav-link {{ Request::is('admin/blog/view') || Request::is('admin/blog/app-download-setup') || Request::is('admin/blog/priority-setup')  ? 'active' : '' }}"
                                   href="{{ route('admin.blog.view') }}">
                                    <span class="text-truncate">{{ translate('list') }}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif
            @endif

            @if(Helpers::module_permission_check('people'))
                <li class="nav-item nav-item_title {{ (Request::is('admin/customer/list') || Request::is('admin/customer/view*') || Request::is('admin/customer/subscriber-list')||Request::is('admin/vendors/add') || Request::is('admin/vendors/list') || Request::is('admin/delivery-man*')) ? 'scroll-here' : '' }}">
                    <small class="nav-subtitle" title="">{{ translate('user_management') }}</small>
                </li>

                <li class="{{ (Request::is('admin/customer/wallet*') || Request::is('admin/customer/list') || Request::is('admin/customer/view*') || Request::is('admin/reviews*') || Request::is('admin/customer/loyalty/report')) ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ (Request::is('admin/customer/wallet*') || Request::is('admin/customer/list') || Request::is('admin/customer/view*') || Request::is('admin/reviews*') || Request::is('admin/customer/loyalty/report')) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('customers') }}">
                        <i class="fi fi-sr-user"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                               {{ translate('customers') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('customers') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/customer/list') || Request::is('admin/customer/view*') ? 'active' : '' }}"
                               href="{{ route('admin.customer.list') }}" title="{{ translate('Customer_List') }}">
                                <span class="text-truncate">{{ translate('customer_List') }} </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/reviews*') ? 'active' : '' }}"
                               href="{{ route('admin.reviews.list') }}" title="{{ translate('customer_Reviews') }}">
                                <span class="text-truncate">
                                    {{ translate('customer_Reviews') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/customer/wallet/report') ? 'active' : '' }}"
                               title="{{ translate('wallet') }}" href="{{ route('admin.customer.wallet.report') }}">
                                <span class="text-truncate">
                                    {{ translate('wallet') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/customer/wallet/bonus-setup') || Request::is('admin/customer/wallet/bonus-setup/edit/*')  ? 'active' : '' }}"
                               title="{{ translate('wallet_Bonus_Setup') }}"
                               href="{{ route('admin.customer.wallet.bonus-setup') }}">
                                <span class="text-truncate">
                                    {{ translate('wallet_Bonus_Setup') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/customer/loyalty/report') ? 'active' : '' }}"
                               title="{{ translate('loyalty_Points') }}"
                               href="{{ route('admin.customer.loyalty.report') }}">
                                <span class="text-truncate">
                                    {{ translate('loyalty_Points') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="{{ Request::is('admin/vendors*') || (Request::is('admin/orders/details/*') && request()->has('vendor-order-list')) ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/vendors*') || (Request::is('admin/orders/details/*') && request()->has('vendor-order-list')) ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('vendors') }}">
                        <i class="fi fi-sr-seller"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('vendors') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('vendors') }}</li>
                        <li class="nav-item ">
                            <a class="nav-link {{ Request::is('admin/vendors/add') ? 'active' : '' }}"
                               title="{{ translate('add_New_Vendor') }}"
                               href="{{ route('admin.vendors.add') }}">
                                <span class="text-truncate">
                                    {{ translate('add_New_Vendor') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/vendors/list') ||Request::is('admin/vendors/view*') ? 'active' : '' }}"
                               title="{{ translate('vendor_List') }}" href="{{ route('admin.vendors.vendor-list') }}">
                                <span class="text-truncate">
                                    {{ translate('vendor_List') }}
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/vendors/withdraw-list')|| Request::is('admin/vendors/withdraw-view/*') ? 'active' : '' }}"
                               href="{{ route('admin.vendors.withdraw_list') }}" title="{{ translate('withdraws') }}">
                                <span class="text-truncate">{{ translate('withdraws') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="{{ Request::is('admin/delivery-man*') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle text-capitalize {{ Request::is('admin/delivery-man*') ? 'active' : '' }}"
                       href="javascript:"
                       title="{{ translate('delivery_men') }}">
                        <i class="fi fi-sr-person-carry-box"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('delivery_men') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('delivery_men') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/delivery-man/add') ? 'active' : '' }}"
                               href="{{ route('admin.delivery-man.add') }}" title="{{ translate('add_new') }}">
                                <span class="text-truncate">{{ translate('add_new') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/delivery-man/list') || Request::is('admin/delivery-man/update*')  || Request::is('admin/delivery-man/order-history-log*') || Request::is('admin/delivery-man/order-wise-earning*') ? 'active' : '' }}"
                               href="{{ route('admin.delivery-man.list') }}"
                               title="{{ translate('list') }}">
                                <span class="text-truncate">{{ translate('list') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ Request::is('admin/delivery-man/withdraw-list') || Request::is('admin/delivery-man/withdraw-view*') ? 'active' : '' }}"
                               href="{{ route('admin.delivery-man.withdraw-list') }}"
                               title="{{ translate('withdraws') }}">
                                <span class="text-truncate">{{ translate('withdraws') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link  {{ Request::is('admin/delivery-man/emergency-contact') ? 'active' : '' }}"
                               href="{{ route('admin.delivery-man.emergency-contact.index') }}"
                               title="{{ translate('emergency_contact') }}">
                                <span class="text-truncate">{{ translate('Emergency_Contact') }}</span>
                            </a>
                        </li>
                    </ul>
                </li>

                @if(auth('admin')->user()->admin_role_id==1)
                    <li class=" {{ (Request::is('admin/employee*') || Request::is('admin/custom-role*')) ? 'sub-menu-opened' : '' }}">
                        <a class="nav-link nav-link-toggle {{ (Request::is('admin/employee*') || Request::is('admin/custom-role*')) ? 'active' : '' }}"
                           href="javascript:" title="{{ translate('employees') }}">
                            <i class="fi fi-sr-employee-man-alt"></i>
                            <span
                                class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                                <span class="text-truncate max-w-180">
                                    {{ translate('employees') }}
                                </span>
                                <i class="fi fi-sr-angle-down"></i>
                            </span>
                        </a>
                        <ul class="aside-submenu navbar-nav">
                            <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('employees') }}</li>
                            <li class="nav-item">
                                <a class="nav-link {{ Request::is('admin/custom-role*') ? 'active' : '' }}"
                                   href="{{ route('admin.custom-role.create') }}"
                                   title="{{ translate('employee_Role_Setup') }}">
                                    <span class="text-truncate">{{ translate('employee_Role_Setup') }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ (Request::is('admin/employee/list') || Request::is('admin/employee/add') || Request::is('admin/employee/update*')) ? 'active' : '' }}"
                                   href="{{ route('admin.employee.list') }}" title="{{ translate('employees') }}">
                                    <span class="text-truncate">{{ translate('employees') }}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                <li>
                    <a class="nav-link {{ Request::is('admin/customer/subscriber-list') ? 'active' : '' }}"
                       href="{{ route('admin.customer.subscriber-list') }}" title="{{ translate('subscribers') }}">
                        <i class="fi fi-sr-user"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('subscribers') }}
                        </span>
                    </a>
                </li>
            @endif

            @if(Helpers::module_permission_check('business_settings'))
                <li class="nav-item nav-item_title">
                    <small class="nav-subtitle" title="">
                        {{ translate('Business_Settings') }}
                    </small>
                </li>

                <li>
                    <a class="nav-link {{
                                (Request::is('admin/business-settings/web-config') ||
                                Request::is('admin/business-settings/web-config/refund-setup') ||
                                Request::is('admin/business-settings/website-setup') ||
                                Request::is('admin/product-settings')||
                                Request::is('admin/business-settings/payment-method/payment-option') ||
                                Request::is('admin/business-settings/vendor-settings') ||
                                Request::is('admin/customer/customer-settings') ||
                                Request::is('admin/business-settings/delivery-man-settings') ||
                                Request::is('admin/business-settings/shipping-method/update'.'*') ||
                                Request::is('admin/business-settings/shipping-method/index') ||
                                Request::is('admin/business-settings/order-settings/index') ||
                                Request::is('admin/business-settings/invoice-settings') ||
                                Request::is('admin/business-settings/delivery-zone')) ? 'active' : '' }}"
                       href="{{ route('admin.business-settings.web-config.index') }}"
                       title="{{ translate('Business_Setup') }}">
                        <i class="fi fi-sr-settings"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Business_Setup') }}
                        </span>
                    </a>
                </li>


                @if(getCheckAddonPublishedStatus(moduleName: 'TaxModule'))
                    <li>
                        <a class="nav-link nav-link-toggle
                        @foreach(include(base_path("Modules/TaxModule/Addon/tax_routes.php")) as $route)
                            {{ strstr(Request::url(), $route['path']) ? 'active' : '' }}
                        @endforeach" href="javascript:" title="{{ translate('System_Vat/Tax') }}">
                            <i class="fi fi-rr-calculator-math-tax"></i>
                            <span
                                class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('System_Vat/Tax') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                        </a>
                        <ul class="aside-submenu navbar-nav">
                            <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">
                                {{ translate('System_Vat/Tax') }}
                            </li>

                            @foreach(include(base_path("Modules/TaxModule/Addon/tax_routes.php")) as $route)
                                <li>
                                    <a class="nav-link {{strstr(Request::url(), $route['path']) ? 'active' : '' }}"
                                       href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                    <span class="text-truncate">
                                        {{ translate($route['name']) }}
                                    </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endif

                <li>
                    <a class="nav-link {{ Request::is('admin/business-settings/inhouse-shop') ? 'active' : '' }}"
                       href="{{ route('admin.business-settings.inhouse-shop') }}"
                       title="{{ translate('Inhouse_Shop') }}">
                        <i class="fi fi-sr-shop"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Inhouse_Shop') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{
                            (Request::is('admin/seo-settings/web-master-tool') ||
                            Request::is('admin/seo-settings/robot-txt') ||
                            Request::is('admin/seo-settings/sitemap') ||
                            Request::is('admin/seo-settings/robots-meta-content*') ||
                            Request::is('admin/seo-settings/error-logs/index')) ? 'active' : ''
                        }}"
                       href="{{ route('admin.seo-settings.web-master-tool') }}" title="{{ translate('SEO_Settings') }}">
                        <i class="fi fi-sr-analyse"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('SEO_Settings') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/business-settings/priority-setup') ? 'active' : '' }}"
                       href="{{ route('admin.business-settings.priority-setup.index') }}"
                       title="{{ translate('Priority_Setup') }}">
                        <i class="fi fi-sr-list-timeline"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Priority_Setup') }}
                        </span>
                    </a>
                </li>

                <li class="{{ Request::is('admin/pages-and-media*') || Request::is('admin/pages-and-media/social-media') ? 'sub-menu-opened' : '' }}">
                    <a class="nav-link nav-link-toggle {{ Request::is('admin/pages-and-media*') || Request::is('admin/pages-and-media/social-media') || Request::is('admin/helpTopic/*') ? 'active' : '' }}"
                       href="javascript:" title="{{ translate('Pages_&_Media') }}">
                        <i class="fi fi-sr-document"></i>
                        <span
                            class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('Pages_&_Media') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                    </a>
                    <ul class="aside-submenu navbar-nav">
                        <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">{{ translate('pages_&_Media') }}</li>
                        <li class="nav-item">
                            <a class="nav-link {{(
                            Request::is('admin/pages-and-media/list') ||
                            Request::is('admin/pages-and-media/page*') ||
                            Request::is('admin/pages-and-media/privacy-policy') ||
                            Request::is('admin/pages-and-media/about-us') ||
                            Request::is('admin/helpTopic/index') ||
                            Request::is('admin/pages-and-media/features-section') ||
                            Request::is('admin/pages-and-media/company-reliability')) ? 'active' : '' }}"
                               href="{{ route('admin.pages-and-media.list') }}"
                               title="{{ translate('business_Pages') }}">
                                <span class="text-truncate">
                                    {{ translate('business_Pages') }}
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link {{ Request::is('admin/pages-and-media/social-media') ? 'active' : '' }}"
                               href="{{ route('admin.pages-and-media.social-media') }}"
                               title="{{ translate('social_Media_Links') }}">
                                <span class="text-truncate">
                                    {{ translate('social_Media_Links') }}
                                </span>
                            </a>
                        </li>


                        <li>
                            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/*') ? 'active' : '' }}"
                               href="{{ route('admin.pages-and-media.vendor-registration-settings.index') }}"
                               title="{{ translate('vendor_Registration') }}">
                                <span class="text-truncate">
                                    {{ translate('vendor_Registration') }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>
            @endif

            @if(Helpers::module_permission_check('system_settings'))
                <li class="nav-item nav-item_title">
                    <small class="nav-subtitle" title="">
                        {{ translate('System_Settings') }}
                    </small>
                </li>

                <li>
                    <a class="nav-link
                {{
                    (Request::is('admin/system-setup/environment-setup') ||
                    Request::is('admin/system-setup/app-settings') ||
                    Request::is('admin/system-setup/sitemap') ||
                    Request::is('admin/system-setup/currency/view') ||
                    Request::is('admin/system-setup/web-config/db-index') ||
                    Request::is('admin/system-setup/language*') ||
                    Request::is('admin/system-setup/software-update') ||
                    Request::is('admin/system-setup/web-config/app-settings') ||
                    Request::is('admin/system-setup/invoice-settings/') ||
                    Request::is('admin/business-settings/delivery-zone')) ||
                    Request::is('admin/system-setup/db-index') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.environment-setup') }}"
                       title="{{ translate('System_Setup') }}">
                        <i class="fi fi-sr-customize"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('System_Setup') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link
                {{
                    Request::is('admin/system-setup/login-settings/login-url-setup')  ||
                    Request::is('admin/system-setup/login-settings/customer-login-setup') ||
                    Request::is('admin/system-setup/login-settings/otp-setup') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.login-settings.customer-login-setup') }}"
                       title="{{ translate('Login_Settings') }}">
                        <i class="fi fi-sr-user-skill-gear"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                        {{ translate('Login_Settings') }}
                    </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/system-setup/email-templates/*') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.email-templates.view', ['admin', EmailTemplateKey::ADMIN_EMAIL_LIST[0]]) }}"
                       title="{{ translate('Email_Template') }}">
                        <i class="fi fi-sr-template"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Email_Template') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/system-setup/file-manager*') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.file-manager.index') }}" title="{{ translate('Gallery') }}">
                        <i class="fi fi-sr-copy-image"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Gallery') }}
                        </span>
                    </a>
                </li>
            @endif

            @if(Helpers::module_permission_check('3rd_party_setup'))
                <li class="nav-item nav-item_title">
                    <small class="nav-subtitle" title="">
                        {{ translate('3rd_Party_Setup') }}
                    </small>
                </li>

                <li>
                    <a class="nav-link
                {{ Request::is('admin/third-party/payment-method') ||
                Request::is('admin/third-party/offline-payment-method/index') ||
                Request::is('admin/third-party/offline-payment-method*') ||
                Request::is('admin/vendors/withdraw-method/*') ? 'active' : '' }}"
                       href="{{ route('admin.third-party.payment-method.index') }}"
                       title="{{ translate('Payment_Methods') }}">
                        <i class="fi fi-sr-credit-card"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Payment_Methods') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link
                {{ Request::is('admin/third-party/firebase-configuration/setup') ||
                    Request::is('admin/third-party/firebase-configuration/authentication') ? 'active' : '' }}"
                       href="{{ route('admin.third-party.firebase-configuration.setup') }}"
                       title="{{ translate('Firebase') }}">
                        <i class="fi fi-sr-database"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Firebase') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/third-party/analytics-index') ? 'active' : '' }}"
                       href="{{ route('admin.third-party.analytics-index') }}"
                       title="{{ translate('Marketing_Tools') }}">
                        <i class="fi fi-sr-tools"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Marketing_Tools') }}
                        </span>
                    </a>
                </li>

                @if(getCheckAddonPublishedStatus(moduleName: 'AI'))
                    <li>
                        <a class="nav-link nav-link-toggle {{
                                Request::is('admin/third-party/ai-setting') || Request::is('admin/third-party/ai-setting/vendors-usage-limits') || Request::is('admin/third-party/ai-setting/customers-usage-limits') ? 'active' : '' }}"
                           href="{{ route('admin.third-party.ai-setting.index') }}" title="{{ translate('AI_Setup') }}">
                            <i class="fi fi-rr-microchip-ai"></i>
                            <span class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between
                            align-items-center">
                                <span class="text-truncate max-w-180">
                                    {{ translate('AI_Setup') }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endif

                <li>
                    <a class="nav-link {{ Request::is('admin/third-party/mail') ||
                            Request::is('admin/third-party/sms-module') ||
                            Request::is('admin/third-party/recaptcha') ||
                            Request::is('admin/third-party/social-login/view') ||
                            Request::is('admin/third-party/social-media-chat/view') ||
                            Request::is('admin/third-party/storage-connection-settings/index') ||
                            Request::is('admin/third-party/map-api') ? 'active'  :'' }}"
                       href="{{ route('admin.third-party.social-login.view') }}"
                       title="{{ translate('Other_Configuration') }}">
                        <i class="fi fi-sr-workflow-setting-alt"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Other_Configuration') }}
                        </span>
                    </a>
                </li>
            @endif

            @if(Helpers::module_permission_check('themes_and_addons'))
                <li class="nav-item nav-item_title">
                    <small class="nav-subtitle" title="">
                        {{ translate('Themes_&_Addons') }}
                    </small>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/system-setup/theme/setup') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.theme.setup') }}" title="{{ translate('Theme_Setup') }}">
                        <i class="fi fi-sr-palette"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Theme_Setup') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/system-setup/addon') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.addon.index') }}" title="{{ translate('System_Addons') }}">
                        <i class="fi fi-sr-book-plus"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('System_Addons') }}
                        </span>
                    </a>
                </li>

                <li>
                    <a class="nav-link {{ Request::is('admin/system-setup/addon-activation') ? 'active' : '' }}"
                       href="{{ route('admin.system-setup.addon-activation.index') }}" title="{{ translate('Addon_Activation') }}">
                        <i class="fi fi-rr-customize"></i>
                        <span class="aside-mini-hidden-element text-truncate flex-grow-1">
                            {{ translate('Addon_Activation') }}
                        </span>
                    </a>
                </li>

                @if(count(config('addon_admin_routes'))>0)
                    <li>
                        <a class="nav-link nav-link-toggle @foreach(config('addon_admin_routes') as $routes)
                            @foreach($routes as $route)
                                {{ strstr(Request::url(), $route['path']) ? 'active' : '' }}
                            @endforeach
                        @endforeach" href="javascript:" title="{{ translate('addon_Menus') }}">
                            <i class="fi fi-rr-home"></i>
                            <span
                                class="aside-mini-hidden-element flex-grow-1 d-flex justify-content-between align-items-center">
                            <span class="text-truncate max-w-180">
                                {{ translate('addon_Menus') }}
                            </span>
                            <i class="fi fi-sr-angle-down"></i>
                        </span>
                        </a>
                        <ul class="aside-submenu navbar-nav">
                            <li class="nav-item px-3 py-2 fw-semibold text-dark bg-section2 aside-mini-show-element">
                                {{ translate('addon_Menus') }}
                            </li>

                            @foreach(config('addon_admin_routes') as $routes)
                                @foreach($routes as $route)
                                    <li>
                                        <a class="nav-link {{strstr(Request::url(), $route['path']) ? 'active' : '' }}"
                                           href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                            <span class="text-truncate">
                                                {{ translate($route['name']) }}
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </li>
                @endif

            @endif

            <?php $checkSetupGuideRequirements = checkSetupGuideRequirements(panel: 'admin'); ?>

            <li class="nav-item {{ $checkSetupGuideRequirements['completePercent'] < 100 ? 'pt-5 mt-5 d-none d-lg-block' : '' }}">
                <div class="pt-4"></div>
            </li>
        </ul>
    </div>
</aside>

@include("layouts.admin.partials._setup-guide")

<div class="offcanvas offcanvas-start bg-panel d-lg-none w-280" tabindex="-1" id="offcanvasAside"
     aria-labelledby="offcanvasAsideLabel">
    <div class="offcanvas-header d-flex align-items-center gap-2 justify-content-between">
        <a class="navbar-logo" href="{{ route('admin.dashboard.index') }}">
            <img height="24" src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}"
                 alt="{{ translate('logo') }}">
        </a>

        <button type="button" class="bg-transparent p-0 text-white border-0" data-bs-dismiss="offcanvas"
                aria-label="Close">
            <i class="fi fi-rr-cross"></i>
        </button>
    </div>

    <div class="offcanvas-body js-offcanvas-body pt-0">

    </div>
</div>
