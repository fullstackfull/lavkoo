<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/delivery-man/earning-statement-overview/*') ?'active':'' }}" id="pills-general-tab"
                href="{{ route('admin.delivery-man.earning-statement-overview', ['id' => $deliveryMan['id']]) }}" role="tab" aria-controls="pills-general"
                aria-selected="true">
                {{translate('overview')}}
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/delivery-man/order-history-log*') ?'active':'' }}" id="pills-general-tab"
                href="{{ route('admin.delivery-man.order-history-log', ['id' => $deliveryMan['id']]) }}" role="tab" aria-controls="pills-general"
                aria-selected="true">
                {{translate('order_History_Log')}}
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/delivery-man/order-wise-earning*') ?'active':'' }}" id="pills-general-tab"
                href="{{ route('admin.delivery-man.order-wise-earning', ['id' => $deliveryMan['id']]) }}" role="tab" aria-controls="pills-general"
                aria-selected="true">
                {{translate('earning')}}
            </a>
        </li>
    </ul>
    <div class="nav--tab__prev">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>

</div>
