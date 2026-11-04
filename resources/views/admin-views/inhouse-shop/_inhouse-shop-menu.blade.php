<div class="position-relative nav--tab-wrapper">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/inhouse-shop') ? 'active' : '' }}"
               href="{{ route('admin.business-settings.inhouse-shop') }}">
                {{ translate('Shop_Settings') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/inhouse-shop/setup') ? 'active' : '' }}"
               href="{{ route('admin.business-settings.inhouse-shop-setup') }}">
                {{ translate('Others') }}
            </a>
        </li>
    </ul>
    <div class="nav--tab__prev d--none">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next d--none">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>

</div>
