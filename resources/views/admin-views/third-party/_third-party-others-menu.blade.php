<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/social-login/view') ?'active':'' }}"
               href="{{ route('admin.third-party.social-login.view') }}">
                {{ translate('social_Media') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/mail') ?'active':'' }}"
               href="{{ route('admin.third-party.mail.index') }}">
                {{ translate('mail_Configuration') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/sms-module') ?'active':'' }}"
               href="{{ route('admin.third-party.sms-module') }}">
                {{ translate('SMS_Configuration') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/map-api') ?'active':'' }}"
               href="{{ route('admin.third-party.map-api') }}">
                {{ translate('Google_Map_APIs') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/delivery-syria') ?'active':'' }}"
               href="{{ route('admin.third-party.delivery-syria') }}">
                {{ translate('delivery_syria') }}
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/storage-connection-settings/index') ?'active':'' }}"
               href="{{ route('admin.third-party.storage-connection-settings.index') }}">
                {{ translate('storage_Connection') }}
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
