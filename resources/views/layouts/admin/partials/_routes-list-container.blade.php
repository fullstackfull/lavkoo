@php use App\Utils\Helpers; @endphp

@if(Helpers::module_permission_check('catalog'))
    <span id="get-product-stock-view" data-stock-limit-page="{{ route('admin.products.stock-limit-list', ['in_house']) }}"></span>
    <span id="get-stock-limit-status" data-action="{{ route('admin.products.stock-limit-status', ['type' => 'in_house']) }}"></span>
@endif

@if(Helpers::module_permission_check('catalog') || Helpers::module_permission_check('marketing'))
    <span id="get-search-product-route" data-action="{{ route('admin.products.search-product') }}"></span>
    <span id="get-search-all-type-product-route" data-action="{{ route('admin.products.search-all-type-product') }}"></span>
@endif

<span id="route-for-real-time-activities" data-route="{{ route('admin.dashboard.real-time-activities') }}"></span>

@if(Helpers::module_permission_check('people'))
    <span id="get-customer-list-route" data-action="{{ route('admin.customer.customer-list-search') }}"></span>
    <span id="get-customer-list-without-all-customer-route" data-action="{{ route('admin.customer.customer-list-without-all-customer') }}"></span>
@endif

@if(Helpers::module_permission_check('marketing'))
    <span id="get-search-product-for-clearance-route" data-action="{{ route('admin.deal.clearance-sale.search-product-for-clearance') }}"></span>
    <span id="get-search-vendor-for-clearance-route" data-action="{{ route('admin.deal.clearance-sale.search-vendor-for-clearance') }}"></span>
    <span id="get-multiple-clearance-product-details-route" data-action="{{ route('admin.deal.clearance-sale.multiple-clearance-product-details') }}"></span>
    <span id="get-clearance-vendor-add-route" data-action="{{ route('admin.deal.clearance-sale.vendor-add') }}"></span>
@endif

<span id="get-multiple-product-details-route" data-action="{{ route('admin.products.multiple-product-details') }}"></span>

@if(Helpers::module_permission_check('orders'))
    <span id="get-orders-list-route" data-action="{{ route('admin.orders.list', ['status' => 'all']) }}"></span>
@endif
