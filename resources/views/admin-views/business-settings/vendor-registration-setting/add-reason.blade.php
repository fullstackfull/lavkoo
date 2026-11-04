<div class="card">
    <div class="card-body">
        <div class="d-flex flex-column gap-3 gap-sm-20">
            <div>
                <h2>{{ translate('reasons_why_vendor_will_sell_business_with_you') }}</h2>
                <p>{{ translate('here_you_can_write_why_vendor_will_sell_in_your_website_reasons_are_shown_as_individual_card_section.') }}</p>
            </div>
            <form action="{{route('admin.pages-and-media.vendor-registration-reason.add')}}" method="post" class="form-advance-validation  form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                @csrf
                <input hidden name="type" value="vendor_registration">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="form-group mb-2">
                                <label class="form-label text-capitalize" for="">{{ translate('reason_title') }}
                                    <span class="text-danger">*</span>
                                    <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                          aria-label="{{ translate('Provide_a_clear_title_for_the_reason_why_vendors_should_join_your_platform._Keep_it_short_and_to_the_point.') }}" data-bs-title="{{ translate('Provide_a_clear_title_for_the_reason_why_vendors_should_join_your_platform._Keep_it_short_and_to_the_point.') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="text" name="title" class="form-control"
                                       data-required-msg="{{ translate('reason_title_is_required') }}"
                                       placeholder="{{translate('enter_title')}}" required="" data-maxlength="50">
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light">0/50</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="">{{ translate('Priority') }}
                                    <span class="text-danger">*</span>
                                    <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                          aria-label="{{ translate('Assign_a_priority_to_this_reason._Lower_numbers_will_be_shown_first_in_the_display.') }}" data-bs-title="{{ translate('Assign_a_priority_to_this_reason._Lower_numbers_will_be_shown_first_in_the_display.') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="priority" class="form-select bg-white">
                                        @for($index = 1; $index <= 15; $index++)
                                            <option value="{{ $index }}">{{ $index }}</option>
                                        @endfor
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="form-group">
                                <label class="form-label text-capitalize" for="">{{ translate('Reason_Description') }}
                                    <span class="text-danger">*</span>
                                    <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                          aria-label="{{ translate('Write_a_detailed_description_explaining_why_vendors_should_choose_your_platform._Focus_on_the_benefits_they_will_get.') }}" data-bs-title="{{ translate('Write_a_detailed_description_explaining_why_vendors_should_choose_your_platform._Focus_on_the_benefits_they_will_get.') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <textarea class="form-control" name="description" rows="6"
                                          placeholder="{{translate('write_description').'...'}}" data-maxlength="150"
                                          data-required-msg="{{ translate('description_is_required') }}"
                                          required></textarea>
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light">0/150</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end flex-wrap gap-3">
                        <button type="reset" class="btn btn-secondary px-4 w-120">{{ translate('Reset') }}</button>
                        <button type="submit" class="btn btn-primary px-4 w-120">{{ translate('save') }}</button>
                    </div>
                </div>
            </form>
            <div class="card card-sm shadow-1">
                <div class="card-body">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                            <h3>{{translate('reason_list')}}
                                <span
                                    class="badge badge-soft-dark radius-50 fz-12">{{ $vendorRegistrationReasons->count() }}</span>
                            </h3>
                            <form action="{{ route('admin.pages-and-media.vendor-registration-settings.with-us') }}"
                                  method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                <div class="input-group">
                                    <input type="search" name="searchValue" class="form-control"
                                           placeholder="{{ translate('Search_by_title') }}"
                                           value="{{ requestString('searchValue') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{translate('SL')}}</th>
                                    <th>{{translate('title')}}</th>
                                    <th>{{translate('description')}}</th>
                                    <th class="text-center">{{translate('priority')}}</th>
                                    <th class="text-center">{{translate('status')}}</th>
                                    <th class="text-center">{{translate('action')}}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($vendorRegistrationReasons as $key => $reason)
                                    <tr>
                                        <td>{{$vendorRegistrationReasons->firstItem()+$key}}</td>
                                        <td>
                                            <div class="max-w-200 line-2 text-wrap">
                                                {{$reason['title']}}
                                            </div>
                                        </td>
                                        <td class="cursor-pointer">
                                            <div class="max-w-280 line-2 text-wrap" data-bs-toggle="tooltip"
                                                 data-bs-placement="top" data-bs-title="{{ $reason['description'] ?? 'N/A' }}">
                                                {{ $reason['description'] ?? 'N/A' }}
                                            </div>
                                        </td>
                                        <td class="text-center">{{$reason['priority']}}</td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center">
                                                <form
                                                    action="{{route('admin.pages-and-media.vendor-registration-reason.update-status')}}"
                                                    id="update-reason-status{{$reason['id']}}-form"
                                                    class="no-reload-form" method="post" enctype="multipart/form-data">
                                                    @csrf
                                                    <input name="id" value="{{$reason['id']}}" hidden>

                                                    <label class="switcher mx-auto"
                                                           for="with-us-reason-status-{{ $reason->id }}">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="status"
                                                            id="with-us-reason-status-{{ $reason->id }}"
                                                            {{$reason['status'] == 1 ? 'checked' : ''}}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#update-reason-status{{$reason['id']}}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                            data-on-title="{{translate('want_to_Turn_ON_the_this_status').'?'}}"
                                                            data-off-title="{{translate('want_to_Turn_OFF_the_this_status').'?'}}"
                                                            data-on-message="<p>{{ translate('once_you_turn_on_the_status_and_complete_the_setup')}}, {{ translate('_this_section_will_be_displayed_on_the_vendor_registration_page') }}</p>"
                                                            data-off-message="<p>{{ translate('once_you_turn_off_the_status_and_complete_the_setup')}}, {{ translate('_this_section_will_not_be_displayed_on_the_vendor_registration_page') }}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-3">
                                                <a class="btn btn-outline-info icon-btn"
                                                   data-bs-toggle="offcanvas"
                                                   data-bs-target="#offcanvasReasonEdit-{{ $reason['id'] }}"
                                                   title="{{ translate('edit')}}"
                                                   data-id=""
                                                >
                                                    <i class="fi fi-sr-pencil"></i>
                                                </a>
                                                <a class="btn btn-outline-danger icon-btn"
                                                   title="{{ translate('delete')}}"
                                                   data-id="{{$reason['id']}}"
                                                   data-bs-toggle="modal"
                                                   data-bs-target="#deleteModal{{$reason['id']}}"
                                                >
                                                    <i class="fi fi-rr-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="table-responsive mt-4">
                            <div class="px-4 d-flex justify-content-lg-end">
                                {{ $vendorRegistrationReasons->links() }}
                            </div>
                        </div>
                        @if(count($vendorRegistrationReasons) == 0 && !request()->has('searchValue'))
                            @include('layouts.admin.partials._empty-state-svg',['text'=>'no_reason_list'],['image'=>'reason'])
                        @endif
                        @if(count($vendorRegistrationReasons) == 0 && request()->has('searchValue'))
                            <div class="p-4 bg-chat rounded text-center">
                                <div class="py-5">
                                    <img src="{{ dynamicAsset('public/assets/back-end/img/empty-blog.png') }}"
                                         width="64"
                                         alt="">
                                    <div class="mx-auto my-3 max-w-353px">
                                        {{ translate('currently_no_reason_available_in_this_state') }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($vendorRegistrationReasons as $key=> $vendorRegistrationReason)
    @include("admin-views.business-settings.vendor-registration-setting.partial._edit-reason-offcanvas", ['vendorRegistrationReason' => $vendorRegistrationReason])
@endforeach

