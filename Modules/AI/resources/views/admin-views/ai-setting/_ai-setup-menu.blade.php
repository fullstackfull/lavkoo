<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/ai-setting') ?'active':'' }}"
               href="{{ route('admin.third-party.ai-setting.index') }}">
                {{ translate('AI_Configuration') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/ai-setting/vendors-usage-limits') ?'active':'' }}"
               href="{{ route('admin.third-party.ai-setting.vendors-usage-limits') }}">
                {{ translate('Setup_AI_Usage_Limit_For_Vendors') }}
            </a>
        </li>
        @if (getCheckAddonPublishedStatus(moduleName: 'Auction'))
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/ai-setting/customers-usage-limits') ?'active':'' }}"
               href="{{ route('admin.third-party.ai-setting.customers-usage-limits') }}">
                {{ translate('Setup_AI_Usage_Limit_For_Customers') }}
            </a>
        </li>
        @endif
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
