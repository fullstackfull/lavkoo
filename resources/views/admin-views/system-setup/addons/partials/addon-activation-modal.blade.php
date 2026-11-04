<div class="modal-header border-0 pb-0 d-flex justify-content-end">
    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
            data-bs-dismiss="modal" aria-label="Close">
    </button>
</div>
<div class="modal-body px-30 py-0 mb-30">
    <div class="mb-20 text-center">
        <img width="140"
             src="{{ getStorageImages(path: null, type: 'backend-basic',source: $path.'/public/addon.png') }}"
             alt="" class="dark-support"/>
    </div>
    <div class="text-center mb-30">
        <h2 class="mb-2">{{ $addonName }}</h2>
        <p class="mb-0">{{ translate('to_active_this_addon_please_fill_the_following_information.') }}</p>
    </div>

    <form id="addon-list-activation-form" action="{{ route('admin.system-setup.addon-activation.activate-from-list') }}" method="post"
          class="form-advance-validation non-ajax-form-validate addon-list-activation-form" autocomplete="off" novalidate="novalidate">
        @csrf
        <input type="hidden" name="addon_name" value="{{ strtolower($fullData['module_name']) }}">
        <input type="hidden" name="software_type" value="addon">
        <input type="hidden" name="software_id" value="{{ base64_encode($fullData['software_id']) }}">
        <input type="hidden" name="path" value="{{ $path }}">

        <div class="bg-section rounded p-12 p-sm-20 mb-30">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label text-capitalize" for="addon_person_name">
                            {{ translate('Name') }}
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="addon_person_name" name="name" class="form-control"
                               placeholder="{{ translate('ex') }}: {{ 'Jone Doe' }}"
                               data-required-msg="{{ translate('name_is_field_is_required') }}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label text-capitalize" for="addon_person_email">
                            {{ translate('Email') }}
                            <span class="text-danger">*</span>
                        </label>
                        <input type="email" id="addon_person_email" name="email" class="form-control"
                               placeholder="{{ translate('ex') }}: {{ 'jone-doe@example.com' }}"
                               data-required-msg="{{ translate('email_is_field_is_required') }}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label text-capitalize" for="addon_username">
                            {{ translate('User_Name') }}
                            <span class="text-danger">*</span>
                            <span class="tooltip-icon"
                                  data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  aria-label="{{ translate('please_use_the_username_exactly_as_it_is,_without_any_spaces..') }} {{ translate('make_sure_to_enter_the_name_correctly.') }}"
                                  data-bs-title="{{ translate('please_use_the_username_exactly_as_it_is,_without_any_spaces..') }} {{ translate('make_sure_to_enter_the_name_correctly.') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" id="addon_username" name="username" class="form-control"
                               placeholder="{{ translate('ex') }}: {{ 'jonedoe3050' }}"
                               data-required-msg="{{ translate('username_is_field_is_required') }}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label text-capitalize" for="addon_purchase_key">
                            {{ translate('Purchase_Code') }}
                            <span class="text-danger">*</span>
                            <span class="tooltip-icon"
                                  data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  aria-label="{{ translate('please_check_your_purchase_code_before_proceeding_with_the_update.') }}"
                                  data-bs-title="{{ translate('please_check_your_purchase_code_before_proceeding_with_the_update.') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" id="addon_purchase_key" name="purchase_key" class="form-control"
                               placeholder="{{ translate('ex') }}: {{ 'CAWFRWRAAWRCAWRA' }}"
                               data-required-msg="{{ translate('purchase_key_field_is_required') }}" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-center gap-20">
            <button type="button" class="btn btn-secondary flex-grow-1" data-bs-dismiss="modal">
                {{ translate('cancel') }}
            </button>
            <button type="{{ getDemoModeFormButton(type: 'button') }}"
                    class="btn btn-primary flex-grow-1 addon-list-activation-submit {{ getDemoModeFormButton(type: 'class') }}">
                <span class="spinner-border spinner-border-sm me-2 d--none submit-spinner" role="status" aria-hidden="true"></span>
                <span class="submit-label">{{ translate('activate') }}</span>
            </button>
        </div>
    </form>
</div>
