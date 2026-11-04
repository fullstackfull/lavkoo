<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/deal/clearance-sale') ? 'active' : '' }}"
            href="{{ route('admin.deal.clearance-sale.index') }}"
            aria-selected="true"
            >
            {{ translate('Manage_Inhouse_Offer') }}
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/deal/clearance-sale/vendor-offers') ? 'active' : '' }}"
            href="{{ route('admin.deal.clearance-sale.vendor-offers') }}"
            aria-selected="true"
            >
            {{ translate('Manage_Vendor_Offer') }}
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link {{ Request::is('admin/deal/clearance-sale/priority-setup') ? 'active' : '' }}"
            href="{{ route('admin.deal.clearance-sale.priority-setup') }}"
            aria-selected="true"
            >
            {{ translate('priority_setup') }}
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
