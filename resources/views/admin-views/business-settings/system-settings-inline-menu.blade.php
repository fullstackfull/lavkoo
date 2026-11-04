<div class="inline-page-menu my-4">
    <ul class="list-unstyled">
        <li class="{{ Request::is('admin/business-settings/system-setup/environment-setup') ?'active':'' }}">
            <a href="{{route('admin.system-setup.environment-setup') }}">{{ translate('Environment_Settings') }}</a>
        </li>

        <li class="{{ Request::is('admin/system-setup/web-config/app-settings') ?'active':'' }}">
            <a href="{{route('admin.system-setup.app-settings') }}">{{ translate('app_Settings') }}</a>
        </li>

        <li class="{{ Request::is('admin/system-settings/software-update') ?'active':'' }}">
            <a href="{{route('admin.system-setup.software-update') }}">{{ translate('software_Update') }}</a>
        </li>
        <li class="{{ Request::is('admin/business-settings/language') ?'active':'' }}">
            <a href="{{route('admin.system-setup.language.index') }}">{{ translate('language') }}</a>
        </li>
        <li class="{{ Request::is('admin/currency/view') ?'active':'' }}">
            <a href="{{route('admin.system-setup.currency.view') }}">{{ translate('Currency') }}</a>
        </li>
        <li class="{{ Request::is('admin/business-settings/web-config/db-index') ?'active':'' }}">
            <a href="{{route('admin.system-setup.db-index') }}">{{ translate('Clean_Database') }}</a>
        </li>
    </ul>
</div>
