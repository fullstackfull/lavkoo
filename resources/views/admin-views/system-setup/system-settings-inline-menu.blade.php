<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/environment-setup') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.environment-setup') }}">
                {{ translate('Environment_Settings') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/app-settings') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.app-settings') }}">
                {{ translate('App_Settings') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/app-deep-link') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.app-deep-link') }}">
                {{ translate('App_Deep_Link_Setup') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/software-update') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.software-update') }}">
                {{ translate('Software_Update') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/language') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.language.index') }}">
                {{ translate('Language') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/currency/*') ? 'active' : '' }}"
               href="{{ route('admin.system-setup.currency.view') }}">
                {{ translate('Currency') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/system-setup/db-index') ?'active' : '' }}"
               href="{{ route('admin.system-setup.db-index') }}">
                {{ translate('Clean_Database') }}
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
