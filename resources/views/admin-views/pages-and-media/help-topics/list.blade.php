@extends('layouts.admin.app')

@section('title', translate('FAQ'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/new/back-end/img/Pages.png')}}" width="20" alt="">
                {{translate('pages')}}
            </h2>
        </div>
        @include('admin-views.pages-and-media._pages-and-media-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-3 mb-sm-20">
                    <h2>{{ translate('Add_FAQ') }}</h2>
                    <p>{{ translate('configure_the_faqs_and_their_display_priority_to_add_them_to_the_list_and_show_them_to_customers_on_the_app_or_website') }}</p>
                </div>
                <form action="{{ route('admin.helpTopic.add-new') }}" method="post" id="addForm" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                    @csrf
                    <div class="p-12 p-sm-20 bg-section rounded mb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Question') }}<span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Question" data-bs-title="{{ translate('add_the_questions_that_are_commonly_asked_by_customers.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="question"
                                           data-required-msg="{{ translate('question_field_is_required') }}"
                                   placeholder="{{translate('type_Question')}}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Priority') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Priority" data-bs-title="{{ translate('indicate_the_priority_order_in_which_this_faq_should_appear_on_the_customer_website/app_by_selecting_a_number.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <div class="select-wrapper">
                                        <select class="form-select bg-white" name="ranking">
                                           @for($i = 1; $i <= 15; $i++)
                                                <option value="{{ $i }}">{{ $i }}</option>
                                           @endfor
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Answer') }}<span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add Answer" data-bs-title="{{ translate('enter_the_relevant_answers_to_the_questions_that_are_commonly_asked_by_the_customers.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="answer"
                                              data-required-msg="{{ translate('answer_field_is_required') }}"
                                      rows="5" placeholder="{{translate('type_Answer')}}" required></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end flex-wrap gap-3">
                        <button type="reset" class="btn btn-secondary px-4 w-120">{{ translate('Reset') }}</button>
                        <button type="submit" class="btn btn-primary px-4 w-120">{{ translate('Submit') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-column gap-20">
                    <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                        <h3>{{ translate('List_of_FAQ') }}</h3>
                        <form action="{{ route('admin.helpTopic.list') }}" method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <div class="input-group">
                                <input type="search" name="searchValue" class="form-control" placeholder="{{ translate('Search_by_question_or_answer') }}" value="{{ requestString('searchValue') }}">
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
                                    <tr id="data-{{$help->id}}">
                                        <td>{{ $key + 1 }}</td>
                                        <td>
                                            <div class="max-w-200 line-2 text-wrap">
                                                {{ $help['question'] }}
                                            </div>
                                        </td>
                                        <td

                                        >
                                            <div class="max-w-280 line-2 text-wrap" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ $help['answer'] }}">
                                                {{ $help['answer'] }}
                                            </div>
                                        </td>
                                        <td class="text-center">{{ $help['ranking'] }}</td>

                                        <td>
                                            <form action="{{ route('admin.helpTopic.status', ['id'=>$help['id']])}}"
                                                method="get" id="help-topic-status{{$help['id']}}-form"
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
                                                <a class="btn btn-outline-info icon-btn"
                                                    data-bs-toggle="offcanvas" data-bs-target="#offcanvasFaqEdit-{{ $help['id'] }}"
                                                    title="{{ translate('edit')}}"
                                                    data-id=""
                                                    >
                                                        <i class="fi fi-sr-pencil"></i>
                                                </a>
                                                <a title="Delete" class="btn btn-outline-danger icon-btn"
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
                    @if(count($helps)==0)
                        @include('layouts.admin.partials._empty-state-svg',['text'=>'no_faqs_available_in_this_list'],['image'=>'faqs'])
                    @endif
                </div>
            </div>
        </div>
    </div>

    @foreach($helps as $key=> $help)
        @include("admin-views.pages-and-media.help-topics.partials._faq-edit-offcanvas", ['help' => $help])
    @endforeach

    @include("layouts.admin.partials.offcanvas._faq-setup")
@endsection

@push('script')
@endpush
