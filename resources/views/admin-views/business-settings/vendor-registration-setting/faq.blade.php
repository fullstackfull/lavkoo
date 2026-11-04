@extends('layouts.admin.app')

@section('title', translate('FAQs'))
@section('content')
    <div class="content container-fluid">
        @include('admin-views.business-settings.vendor-registration-setting.partial.inline-menu')

        <div class="card mb-3 mb-sm-20">
            <div class="card-body">
                <div class="row align-items-center gy-3">
                    <div class="col-md-9">
                        <div>
                            <h2 class="text-capitalize">{{ translate('FAQs_Section') }}</h2>
                            <p class="fs-12 mb-0">
                                {{ translate('in_this_section_you_can_add_some_frequently_asked_questions_for_vendors') }}
                            </p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex justify-content-between align-items-center gap-3 rounded px-20 py-3 user-select-none bg-section">
                            <span class="fw-semibold text-dark">{{ translate('Status') }}</span>
                            <form action="{{ route('admin.helpTopic.feature-status-update') }}" method="post"
                                  id="vendor-registration-faq-status-form" class="no-reload-form">
                                @csrf
                                <label class="switcher mx-auto" for="vendor-registration-faq-status">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="vendor-registration-faq-status"
                                        {{ getWebConfig('vendor_registration_faq_status') == 1 ? 'checked' : ''  }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#vendor-registration-faq-status-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/faq-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/faq-off.png') }}"
                                        data-on-title="{{ translate('are_you_sure_to_turn_on_the_faq_status') }}"
                                        data-off-title="{{ translate('are_you_sure_to_turn_off_the_faq_status') }}"
                                        data-on-message="<p>{{ translate('once_you_turn_on_this_blog_it_will_be_visible_to_the_blog_list_for_users.') }}</p>"
                                        data-off-message="<p>{{ translate('when_you_turn_off_this_blog_it_will_not_be_visible_to_the_blog_list_for_users') }}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-column gap-3 gap-sm-20">
                    <div>
                        <h2>{{ translate('Add_FAQ') }}</h2>
                        <p>{{ translate('add_faqs_from_this_section_you_can_add_ass_many_question_as_you_want') }}.</p>
                    </div>
                    <form action="{{ route('admin.helpTopic.add-new') }}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                        @csrf
                        <input hidden name="type" value="vendor_registration">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-3 mb-4">
                                <div class="col-lg-6">
                                    <div class="form-group mb-2">
                                        <label class="form-label" for="">{{ translate('Question') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Enter_a_frequently_asked_question_that_vendors_might_have_about_selling_on_your_platform._Keep_it_short,_clear,_and_easy_to_understand.') }}" data-bs-title="{{ translate('Enter_a_frequently_asked_question_that_vendors_might_have_about_selling_on_your_platform._Keep_it_short,_clear,_and_easy_to_understand.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <input type="text" name="question" class="form-control" placeholder="{{translate('enter_question')}}"  data-required-msg="{{ translate('question_is_required') }}" required="" data-maxlength="50">
                                        <div class="d-flex justify-content-end">
                                            <span class="text-body-light">0/50</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Priority') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Set_the_display_order_for_this_FAQ._Lower_numbers_will_appear_first_on_the_vendor_registration_page.') }}" data-bs-title="{{ translate('Set_the_display_order_for_this_FAQ._Lower_numbers_will_appear_first_on_the_vendor_registration_page.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <div class="select-wrapper">
                                            <select name="ranking" class="form-select bg-white">
                                                @for($index = 1; $index <= 15; $index++)
                                                    <option value="{{ $index }}">{{ $index }}</option>
                                                @endfor
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Answer') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Provide a clear and helpful answer to the question. Keep it concise but detailed enough to fully address the vendor’s concern.') }}" data-bs-title="{{ translate('Provide a clear and helpful answer to the question. Keep it concise but detailed enough to fully address the vendor’s concern.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <textarea class="form-control" name="answer" rows="6" placeholder="{{translate('write_answer').'....'}}" data-maxlength="150"  data-required-msg="{{ translate('answer_is_required') }}" required></textarea>
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
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                            <h3>
                                {{ translate('FAQ_List') }}
                                <span class="badge badge-soft-dark radius-50 fz-12">{{ $helps->count() }}</span>
                            </h3>
                            <form action="" method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                @csrf
                                <div class="input-group">
                                    <input type="search" name="searchValue" class="form-control" placeholder="{{ translate('Search_here') }}" value="{{ requestString('searchValue') }}">
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
                                        <th>{{translate('question')}}</th>
                                        <th class="min-w-200">{{translate('answer')}}</th>
                                        <th class="text-center">{{translate('priority')}}</th>
                                        <th class="text-center">{{translate('status')}} </th>
                                        <th class="text-center">{{translate('action')}}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($helps as $key => $help)
                                        <tr id="data-{{$help['id']}}">
                                            <td>{{$helps->firstItem()+$key}}</td>
                                            <td>
                                                <div class="max-w-200 line-2 text-wrap">
                                                    {{ $help['question'] }}
                                                </div>
                                            </td>
                                            <td>
                                                <div class="max-w-280 line-2 text-wrap" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ $help['answer'] }}">
                                                    {{ $help['answer'] }}
                                                </div>
                                            </td>
                                            <td class="text-center">{{ $help['ranking'] }}</td>
                                            <td class="text-center">
                                                <form action="{{ route('admin.helpTopic.status', ['id'=>$help['id']])}}"
                                                    method="post" id="help-topic-status{{$help['id']}}-form"
                                                    class="helpTopic_status_form no-reload-form">
                                                    @csrf

                                                    <label class="switcher mx-auto" for="help-topic-status{{$help['id']}}">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="status"
                                                            id="help-topic-status{{$help['id']}}"
                                                            {{ $help['status'] == 1 ? 'checked':'' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#help-topic-status{{$help['id']}}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/faq-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/faq-off.png') }}"
                                                            data-on-title = "{{translate('want_to_Turn_ON_This_FAQ').'?'}}"
                                                            data-off-title = "{{translate('want_to_Turn_OFF_This_FAQ').'?'}}"
                                                            data-on-message = "<p>{{translate('if_you_enable_this_FAQ_will_be_shown_in_the_user_app_and_website')}}</p>"
                                                            data-off-message = "<p>{{translate('if_you_disable_this_FAQ_will_not_be_shown_in_the_user_app_and_website')}}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-center gap-3">
                                                    <a class="btn btn-outline-info icon-btn edit"
                                                    data-bs-toggle="offcanvas" data-bs-target="#offcanvasFaqEdit{{$help['id']}}"
                                                    title="{{ translate('edit')}}"
                                                    data-id="{{ route('admin.helpTopic.update', ['id'=>$help['id']]) }}">
                                                        <i class="fi fi-sr-pencil"></i>
                                                    </a>
                                                    <a class="btn btn-outline-danger icon-btn"
                                                    title="{{ translate('delete')}}"
                                                    data-id="{{$help['id']}}"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal{{$help['id']}}"
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
                                {{ $helps->links() }}
                            </div>
                        </div>
                        @if(count($helps) == 0 && !request()->searchValue))
                            @include('layouts.admin.partials._empty-state-svg',['text'=>'no_help_topic_list'],['image'=>'reason'])
                        @endif
                        @if(count($helps) == 0 && request()->searchValue))
                            <div class="p-4 bg-chat rounded text-center">
                                <div class="py-5">
                                    <img src="{{ dynamicAsset('public/assets/back-end/img/empty-blog.png') }}" width="64"
                                         alt="">
                                    <div class="mx-auto my-3 max-w-353px">
                                        {{ translate('currently_no_faq_available_in_this_state') }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @foreach($helps as $key=> $help)
        @include("admin-views.business-settings.vendor-registration-setting.partial._edit-faq-offcanvas", ['help' => $help])
    @endforeach

    @include("layouts.admin.partials.offcanvas._vendor-reg-faq")
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/business-setting/vendor-registration-setting.js')}}"></script>
@endpush
