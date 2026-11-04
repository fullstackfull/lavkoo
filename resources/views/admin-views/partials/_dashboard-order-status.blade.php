<div class="col-12 col-md-6 col-xl-3">
    <a class="business-analytics border card" href="{{ route('admin.orders.list', ['all']) }}">
        <h4 class="business-analytics__subtitle">{{ translate('total_Order') }}</h4>
        <h3 class="h2">{{ $data['order'] }}</h3>
        <img  src="{{ dynamicAsset(path: 'public/assets/back-end/img/all-orders.png') }}" width="30" height="30" class="position-absolute end-3 top-3" alt="">
    </a>
</div>
<div class="col-12 col-md-6 col-xl-3">
    <a class="business-analytics border get-view-by-onclick card" href="{{ route('admin.vendors.vendor-list') }}">
        <h4>{{ translate('total_Stores') }}</h4>
        <h3 class="h2">{{ $data['store'] }}</h3>
        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-stores.png') }}" class="position-absolute end-3 top-3" alt="">
    </a>
</div>
<div class="col-12 col-md-6 col-xl-3">
    <a class="business-analytics border card">
        <h4 class="business-analytics__subtitle">{{ translate('Total_Products') }}</h4>
        <h3 class="h2">{{ $data['product'] }}</h3>
        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-product.png') }}" class="position-absolute end-3 top-3" alt="">
    </a>
</div>
<div class="col-12 col-md-6 col-xl-3">
    <a class="business-analytics border card" href="{{ route('admin.customer.list') }}">
        <h4 class="business-analytics__subtitle">{{ translate('Total_Customers') }}</h4>
        <h3 class="h2">{{ $data['customer'] }}</h3>
        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-customer.png') }}" class="position-absolute end-3 top-3" alt="">
    </a>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <a class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded" href="{{ route('admin.orders.list',['pending'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/pending.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Pending') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['pending']}}
        </span>
    </a>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <a class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_confirmed" href="{{ route('admin.orders.list',['confirmed'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/confirmed.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Confirmed') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['confirmed']}}
        </span>
    </a>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <a class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_packaging" href="{{ route('admin.orders.list',['processing'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/packaging.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Packaging') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['processing']}}
        </span>
    </a>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <a class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_out-for-delivery" href="{{ route('admin.orders.list',['out_for_delivery'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/out-of-delivery.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Out_For_Delivery') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['out_for_delivery']}}
        </span>
    </a>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_delivered cursor-pointer get-view-by-onclick" data-link="{{ route('admin.orders.list',['delivered'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/delivered.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Delivered') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['delivered'] }}
        </span>
    </div>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_canceled cursor-pointer get-view-by-onclick" data-link="{{ route('admin.orders.list',['canceled'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/canceled.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Canceled') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['canceled'] }}
        </span>
    </div>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_returned cursor-pointer get-view-by-onclick" data-link="{{ route('admin.orders.list',['returned'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/returned.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Returned') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['returned'] }}
        </span>
    </div>
</div>

<div class="col-12 col-md-6 col-xl-3">
    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_failed cursor-pointer get-view-by-onclick" data-link="{{ route('admin.orders.list',['failed'])}}">
        <div class="d-flex gap-3 align-items-center">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/failed-to-deliver.png') }}" alt="">
            <h4 class="mb-0">{{ translate('Failed_To_Delivery') }}</h4>
        </div>
        <span class="h3 mb-0">
            {{ $data['failed'] }}
        </span>
    </div>
</div>
