<form action="{{ route('admin.pages-and-media.vendor-registration-reason.update', ['id'=>$vendorRegistrationReason['id']]) }}" method="post" id="update-form-submit" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasReasonEdit-{{$vendorRegistrationReason['id']}}" aria-labelledby="offcanvasFaqEditLabel">
        <div class="offcanvas-header bg-body">
            <div>
                <h2 class="mb-1 text-capitalize">{{ translate('edit_reason') }}</h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-3 mb-sm-20">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group mb-3">
                        <label class="form-label" for="">{{ translate('Title') }}
                             <span class="text-danger">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Title" data-bs-title="Add Title">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" name="title" class="form-control" value="{{ $vendorRegistrationReason['title'] }}" placeholder="{{translate('enter_title')}}"  data-required-msg="{{ translate('reason_title_is_required') }}" required="" data-maxlength="50">
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/50</span>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label text-capitalize" for="">{{ translate('short_Description') }}    <span class="text-danger">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Reason Description" data-bs-title="Add Reason Description">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <textarea class="form-control" name="description"  rows="4" placeholder="{{translate('write_description').'...'}}" data-maxlength="150"  data-required-msg="{{ translate('description_is_required') }}" required>{{ $vendorRegistrationReason['description'] }}</textarea>
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/150</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="">{{ translate('Priority') }}  <span class="text-danger">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Priority" data-bs-title="Add Priority">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <div class="select-wrapper">
                            <select name="priority" class="form-select bg-white">
                                @for($index = 1; $index <= 15; $index++)
                                    <option value="{{ $index }}" {{ $vendorRegistrationReason['priority'] == $index ? 'selected' : '' }}>{{ $index }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-lg">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary px-3 px-sm-4 flex-grow-1">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn-primary px-3 px-sm-4 flex-grow-1">
                    {{ translate('update') }}
                </button>
            </div>
        </div>
    </div>
</form>

<form action="{{ route('admin.pages-and-media.vendor-registration-reason.delete') }}" method="post">
    <input type="hidden" name="id" value="{{ $vendorRegistrationReason['id'] }}">
    @csrf
    <div class="modal fade" id="deleteModal{{ $vendorRegistrationReason['id'] }}" tabindex="-1" aria-labelledby="deleteModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}" width="80" class="mb-20" id="" alt="">
                        <h2 class="modal-title mb-3" id="">{{ translate('want_to_delete_this_reason') }}?</h2>
                        <div class="text-center" id="">{{ translate('do_you_want_to_delete_this_reason') . '? ' . translate('you_will_not_be_able_to_revert_this_once_it_is_deleted.') }}</div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                data-bs-dismiss="modal">{{ translate('No') }}</button>
                        <button type="submit" class="btn btn-danger max-w-120 flex-grow-1"
                                data-bs-dismiss="modal">{{ translate('Yes,_Delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
