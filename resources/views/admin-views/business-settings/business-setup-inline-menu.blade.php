<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab p-0" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/web-config') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.web-config.index') }}">
                {{ translate('General') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/website-setup') ? 'active' : '' }}"
            href="{{ route('admin.business-settings.website-setup') }}">
                {{ translate('Website_Setup') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/vendor-settings') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.vendor-settings.index') }}">
                {{ translate('Vendors') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/product-settings') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.product-settings.index') }}">
                {{ translate('Products') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/delivery-man-settings') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.delivery-man-settings.index') }}">
                {{ translate('Delivery_Men') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/customer-settings') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.customer-settings') }}">
                {{ translate('Customer') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/order-settings/index') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.order-settings.index') }}">
                {{ translate('Orders') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/abandoned-cart/index') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.abandoned-cart.index') }}">
                {{ translate('abandoned_cart_recovery') }}
            </a>
        </li>

        @if(Route::has('admin.business-settings.auction-config') && getCheckAddonPublishedStatus(moduleName: 'Auction'))
            <li class="nav-item">
                <a class="nav-link {{ Request::is('admin/business-settings/auction-config') ? 'active' : '' }}"
                   href="{{ route('admin.business-settings.auction-config') }}">
                    {{ translate('Auction') }}
                </a>
            </li>
        @endif

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/refund-setup') ? 'active' : '' }}"
            href="{{ route('admin.business-settings.refund-setup') }}">
                {{ translate('Refund') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/shipping-method/index') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.shipping-method.index') }}">
                {{ translate('Shipping_Method') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/delivery-zone') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.delivery-zone.index') }}">
                {{ translate('Delivery_Zone') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/business-settings/invoice-settings') ? 'active' : '' }}"
                href="{{ route('admin.business-settings.invoice-settings.index') }}">
                {{ translate('Invoice') }}
            </a>
        </li>
    </ul>
    <div class="nav--tab__prev">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next">
        <button class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>

</div>
