@php
    use App\Enums\ViewPaths\Admin\VendorRegistrationSetting;
@endphp
<div class="mb-3 mb-sm-4">
    <h1 class="mb-0">
        {{translate('vendor_registration')}}
    </h1>
</div>

<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/index') ?'active':'' }}"
                href="{{ route('admin.pages-and-media.vendor-registration-settings.index') }}">{{translate('header')}}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/with-us') ?'active':'' }}"
                href="{{ route('admin.pages-and-media.vendor-registration-settings.with-us') }}">{{translate('why_Sell_With_Us')}}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/business-process') ?'active':'' }}"
                href="{{ route('admin.pages-and-media.vendor-registration-settings.business-process') }}">{{translate('business_Process')}}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/download-app') ?'active':'' }}"
                href="{{ route('admin.pages-and-media.vendor-registration-settings.download-app') }}">{{translate('download_App')}}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/vendor-registration-settings/faq') ?'active':'' }}"
                href="{{ route('admin.pages-and-media.vendor-registration-settings.faq') }}">{{translate('FAQ')}}
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
