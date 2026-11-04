<div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
    <div class="d-flex flex-column justify-content-center align-items-center mb-3 position-relative">
        @if($data['type'] == 'firebase')
            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/firebase-settings.png') }}"
                 class="status-icon" alt="" width="80"/>
        @else
            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/google-logo.png') }}"
                 class="status-icon" alt="" width="80"/>
        @endif
    </div>
    <h5 class="modal-title">
        {{ translate('set_up_firebase_configuration_first') }}
    </h5>
    <div class="text-center">
        {{ translate('it_looks_like_your_'.$data['type'].'_login_configuration_is_not_set_up_yet.') }}
        {{ translate('to_enable_the_'.$data['type'].'_login_option_please_set_up_the_'.$data['type'].'_configuration_first.') }}
    </div>
    <div class="text-center py-3">
        <a class="text-decoration-underline fw-bold"
           href="{{ route('admin.third-party.firebase-configuration.setup') }}" target="_blank">
            {{ translate('go_to_Firebase_configuration') }}
        </a>
    </div>
</div>
<div class="d-flex justify-content-center gap-3 mt-3">
    <button type="button" class="btn btn-primary min-w-120" data-bs-dismiss="modal">
        {{ translate('ok') }}
    </button>
    <button type="button" class="btn btn-secondary min-w-120" data-bs-dismiss="modal">
        {{ translate('cancel') }}
    </button>
</div>
