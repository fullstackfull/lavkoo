@extends('layouts.admin.app')

@section('title', translate('404_logs'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/seo-settings.svg') }}" alt="">
                {{ translate('SEO_Settings') }}
            </h2>
        </div>
        @include('admin-views.seo-settings._inline-menu')
        <div class="card shadow-none">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="">
                    <h3 class="">{{translate('404_Logs')}}</h3>
                    <p class="m-0">
                        {{ translate('track_instances_of_page_not_found_errors_faced_by_users_on_your_website') }}
                        <a href="{{ 'https://6amtech.com/blog/404-logs/' }}" target="_blank" class="text-primary text-decoration-underline fw-semibold text-nowrap">
                            {{translate('Learn_more')}}
                        </a>
                    </p>
                </div>
                <div>
                    <button data-bs-toggle="modal" data-bs-target="#cleanErrorLog" type="button" class="btn bg-danger bg-opacity-10 text-danger border border-danger text-capitalize d-none {{ getDemoModeFormButton(type: 'class') }}" id="clear-all-log">
                        {{ translate('clear_all_log') }}
                    </button>


                    </form>
                </div>
            </div>
            <div class="card-body px-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th class="w-95px">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="check-item-2">
                                        <input type="checkbox" id="master-check-box" class="form-check-input checkbox--input checkbox--input_lg">
                                    </span>
                                    <span>{{translate('URL')}}</span>
                                </div>
                            </th>
                            <th class="w-45 text-center">{{translate('hits')}}</th>
                            <th class="w-200 text-center text-capitalize">{{translate('last_hit_date')}}</th>
                            <th class="w-200 text-center text-capitalize">{{translate('redirection_link')}}</th>
                            <th class="text-center w-60">{{translate('action')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($errorLogs as $key=>$errorLog)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2 overflow-hidden text-wrap">
                                    <span class="check-item-2">
                                        <input type="checkbox" class="row-check-box form-check-input checkbox--input checkbox--input_lg" value="{{$errorLog['id']}}">
                                    </span>
                                        <a href="javascript:" class="text-primary text-decoration-underline">{{$errorLog['url']}}</a>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="fw-semibold text-dark">{{$errorLog['hit_counts']}}</span>
                                </td>
                                <td class="text-center">
                                    <span>{{date('M y', $errorLog['update_at'])}} <small>{{date('h:i A',$errorLog['update_at'])}}</small></span>
                                </td>
                                <td>
                                    @if($errorLog['redirect_url'])
                                        <div class="d-flex flex-wrap justify-content-center">
                                            <button class="btn btn-outline-primary edit-content-btn text-capitalize edit-redirect-link" data-id="{{$errorLog['id']}}"
                                                data-redirect-url="{{$errorLog['redirect_url']}}"
                                                data-redirect-status="{{$errorLog['redirect_status']}}"
                                            >
                                                <i class="fi fi-sr-pencil"></i> {{translate('edit_link')}}
                                            </button>
                                        </div>
                                    @else
                                        <div class="d-flex flex-wrap justify-content-center">
                                            <button class="btn btn-outline-success add-content-btn text-capitalize add-redirect-link" data-id="{{$errorLog['id']}}">
                                                <i class="fi fi-rr-plus"></i> {{translate('add_link')}}
                                            </button>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-center align-items-center mt-1">
                                        <a class="{{config('app.mode')!='demo'? 'delete-data' : 'call-demo-alert'}}" href="javascript:" data-id="error-logs-{{ $errorLog['id']}}">
                                            <img src="{{dynamicAsset('public/assets/new/back-end/img/delete-outlined.png')}}" alt="" width="30">
                                        </a>
                                    </div>
                                    <form action="{{ route('admin.seo-settings.error-logs.index',['id'=>$errorLog['id']]) }}"
                                          method="post" id="error-logs-{{ $errorLog['id']}}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="page-area px-4 pt-4">
                    <div class="d-flex align-items-center justify-content-end">
                        <div>
                            {{ $errorLogs->links() }}
                        </div>
                    </div>
                </div>
                @if(count($errorLogs) == 0)
                    @include('layouts.admin.partials._empty-state',['text'=>'no_error_logs_data_found'],['image'=>'default'])
                @endif
            </div>
        </div>
    </div>

    <div class="modal fade" id="add-edit-modal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.seo-settings.error-logs.index') }}" method="post">
                    @csrf
                    <input name="id" hidden>
                    <div class="modal-header">
                        <h3 class="modal-title w-100 text-center">{{translate('redirection_link')}}</h3>
                        <button type="button" class="position-absolute right-0 top-2 bg-transparent border-0 p-3 opacity--40 fz-16" data-bs-dismiss="modal">
                            <i class="tio-clear-circle"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">{{translate('redirection_link')}}</label>
                        <input type="text" class="form-control" name="redirect_url" placeholder="{{translate('enter_you_link')}}">

                        <label class="form-label mt-3">{{translate('Status')}}</label>
                        <div class="form-group d-flex gap-4 align-items-center">
                            <div class="custom-control custom-radio d-flex gap-2 align-items-center pointer">
                                <input type="radio" class="custom-control-input" value="301"
                                       name="redirect_status"
                                       id="logs_redirect_status_301">
                                <label class="custom-control-label pointer"
                                       for="logs_redirect_status_301">
                                    {{ '301' }}
                                </label>
                            </div>
                            <div class="custom-control custom-radio d-flex gap-2 align-items-center pointer">
                                <input type="radio" class="custom-control-input" value="302"
                                       name="redirect_status"
                                       id="logs_redirect_status_302">
                                <label class="custom-control-label pointer"
                                       for="logs_redirect_status_302">
                                    {{ '302' }}
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                class="{{ getDemoModeFormButton(type: 'class') }} btn btn-primary submit-button">
                            {{ translate('submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cleanErrorLog" tabindex="-1"
         aria-labelledby="cleanErrorLog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button"
                            class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <form action="{{ config('app.mode') != 'demo' ? route('admin.seo-settings.error-logs.delete-selected-error-logs') : 'javascript:'}}"
                          method="post">
                        @csrf @method('delete')
                        <div id="selected-ids"></div>
                        <div class="d-flex flex-column align-items-center text-center mb-30">
                            <img
                                src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}"
                                width="80" class="mb-20" id="" alt="">
                            <h2 class="modal-title mb-3">
                                {{ translate('want_to_clean_logs').' ?' }}
                            </h2>
                            <div class="text-center">
                                {{ translate('are_you_sure_want_to_cleaned_the_selected_logs').' ?' }}
                                {{ translate('it_will_be_permanently_deleted_from_your_logs') }}
                            </div>
                        </div>
                        <div class="d-flex justify-content-center gap-3">
                            <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                    data-bs-dismiss="modal">
                                {{ translate('No') }}
                            </button>
                            <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                    class="btn btn-danger max-w-120 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}"
                                    data-bs-dismiss="modal">
                                {{ translate('Yes_Delete') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/error-logs.js')}}"></script>
@endpush
