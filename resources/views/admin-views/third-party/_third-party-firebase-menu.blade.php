<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/firebase-configuration/setup') ?'active':'' }}"
               href="{{ route('admin.third-party.firebase-configuration.setup') }}">
                {{ translate('configuration') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/third-party/firebase-configuration/authentication') ?'active':'' }}"
               href="{{route('admin.third-party.firebase-configuration.authentication')}}">
                {{ translate('authentication') }}
            </a>
        </li>
    </ul>
</div>
