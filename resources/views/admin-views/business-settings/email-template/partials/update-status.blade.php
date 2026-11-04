<div class="card mb-3">
    <div class="card-body">
        <form
            action="{{route('admin.system-setup.email-templates.update-status', [$template['template_name'], $template['user_type']])}}"
            method="post" id="email-template-status-form" enctype="multipart/form-data"
            class="no-reload-form"
        >
            @csrf
            <div class="row align-items-center">
                <div class="col-md-8 col-xl-9">
                    <h2>{{translate('get_email_on_').translate(str_replace('-','_',$template['template_name'])).' ?'}}</h2>
                    <p class="mb-0 fs-12">
                        {{ translate('enable_this_option_to_send_email_notifications.') }}
                    </p>
                </div>
                <div class="col-md-4 col-xl-3">
                    <div class="mt-3 mt-md-0">
                        <div
                            class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                            <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                            <label class="switcher " for="email-template-status">
                                <input
                                    class="switcher_input custom-modal-plugin"
                                    type="checkbox" value="1" name="status"
                                    id="email-template-status"
                                    {{ $template['status'] == 1 ? 'checked':'' }}
                                    data-modal-type="input-change-form"
                                    data-modal-form="#email-template-status-form"
                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/mail-status-on.png') }}"
                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/mail-status-off.png') }}"
                                    data-on-title="{{translate('want_to_Turn_ON_this_mail').'?'}}"
                                    data-off-title="{{translate('want_to_Turn_OFF_this_mail').'?'}}"
                                    data-on-message="<p>{{translate('if_enabled_users_will_receive_this_mail').'.'}}</p>"
                                    data-off-message="<p>{{translate('if_disabled_users_would_not_receive_this_mail').'.'}}</p>"
                                    data-on-button-text="{{ translate('turn_on') }}"
                                    data-off-button-text="{{ translate('turn_off') }}">
                                <span class="switcher_control"></span>
                            </label>
                        </div>
                    </div>
                </div>
        </form>
    </div>
</div>
