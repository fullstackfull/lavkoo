<div class="position-relative nav--tab-wrapper">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/push-notification/index') && (!request('type') || request('type') == 'customer') ? 'active' : '' }}"
               href="{{ route('admin.push-notification.index', ['type' => 'customer']) }}"
               role="tab" aria-selected="true">
                {{ translate('Customer') }}
            </a>
        </li>

        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/push-notification/index') && request('type') == 'vendor' ? 'active' : '' }}"
               href="{{ route('admin.push-notification.index', ['type' => 'vendor']) }}"
               role="tab" aria-selected="true">
                {{ translate('Vendor') }}
            </a>
        </li>

        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/push-notification/index') && request('type') == 'deliveryman' ? 'active' : '' }}"
               href="{{ route('admin.push-notification.index', ['type' => 'deliveryman']) }}"
               role="tab" aria-selected="true">
                {{ translate('Deliveryman') }}
            </a>
        </li>

    </ul>
    <div class="nav--tab__prev">
        <button class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next">
        <button class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>
</div>
