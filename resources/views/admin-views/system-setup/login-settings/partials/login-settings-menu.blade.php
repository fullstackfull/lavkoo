<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/login-settings/customer-login-setup') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.login-settings.customer-login-setup') }}">
                {{ translate('Customer_Login') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/login-settings/otp-setup') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.login-settings.otp-setup') }}">
                {{ translate('OTP_&_Login_Attempts') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/login-settings/login-url-setup') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.login-settings.login-url-setup') }}">
                {{ translate('login_Url') }}
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

