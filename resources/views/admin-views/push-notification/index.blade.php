@php use App\Utils\Helpers; @endphp
@extends('layouts.admin.app')

@section('title', translate('Notification_Messages'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/push-notification.png') }}"
                     alt="">
                {{ translate('Notification_Messages') }}
            </h2>
        </div>
        <div class="row g-4 align-items-center justify-content-between mb-4">
            <div class="col-auto">
                @include('admin-views.push-notification._push-notification-inline-menu')
            </div>
            <div class="col-auto">
                <div class="text-primary d-flex align-items-center gap-3 font-weight-bolder text-capitalize">
                    {{ translate('read_documentation') }}
                    <div class="ripple-animation" data-bs-toggle="modal" data-bs-target="#docsModal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"
                             class="svg replaced-svg">
                            <path
                                d="M9.00033 9.83268C9.23644 9.83268 9.43449 9.75268 9.59449 9.59268C9.75449 9.43268 9.83421 9.2349 9.83366 8.99935V5.64518C9.83366 5.40907 9.75366 5.21463 9.59366 5.06185C9.43366 4.90907 9.23588 4.83268 9.00033 4.83268C8.76421 4.83268 8.56616 4.91268 8.40616 5.07268C8.24616 5.23268 8.16644 5.43046 8.16699 5.66602V9.02018C8.16699 9.25629 8.24699 9.45074 8.40699 9.60352C8.56699 9.75629 8.76477 9.83268 9.00033 9.83268ZM9.00033 13.166C9.23644 13.166 9.43449 13.086 9.59449 12.926C9.75449 12.766 9.83421 12.5682 9.83366 12.3327C9.83366 12.0966 9.75366 11.8985 9.59366 11.7385C9.43366 11.5785 9.23588 11.4988 9.00033 11.4993C8.76421 11.4993 8.56616 11.5793 8.40616 11.7393C8.24616 11.8993 8.16644 12.0971 8.16699 12.3327C8.16699 12.5688 8.24699 12.7668 8.40699 12.9268C8.56699 13.0868 8.76477 13.1666 9.00033 13.166ZM9.00033 17.3327C7.84755 17.3327 6.76421 17.1138 5.75033 16.676C4.73644 16.2382 3.85449 15.6446 3.10449 14.8952C2.35449 14.1452 1.76088 13.2632 1.32366 12.2493C0.886437 11.2355 0.667548 10.1521 0.666992 8.99935C0.666992 7.84657 0.885881 6.76324 1.32366 5.74935C1.76144 4.73546 2.35505 3.85352 3.10449 3.10352C3.85449 2.35352 4.73644 1.7599 5.75033 1.32268C6.76421 0.88546 7.84755 0.666571 9.00033 0.666016C10.1531 0.666016 11.2364 0.884905 12.2503 1.32268C13.2642 1.76046 14.1462 2.35407 14.8962 3.10352C15.6462 3.85352 16.24 4.73546 16.6778 5.74935C17.1156 6.76324 17.3342 7.84657 17.3337 8.99935C17.3337 10.1521 17.1148 11.2355 16.677 12.2493C16.2392 13.2632 15.6456 14.1452 14.8962 14.8952C14.1462 15.6452 13.2642 16.2391 12.2503 16.6768C11.2364 17.1146 10.1531 17.3332 9.00033 17.3327ZM9.00033 15.666C10.8475 15.666 12.4206 15.0168 13.7195 13.7185C15.0184 12.4202 15.6675 10.8471 15.667 8.99935C15.667 7.15213 15.0178 5.57907 13.7195 4.28018C12.4212 2.98129 10.8481 2.33213 9.00033 2.33268C7.1531 2.33268 5.58005 2.98185 4.28116 4.28018C2.98227 5.57852 2.3331 7.15157 2.33366 8.99935C2.33366 10.8466 2.98283 12.4196 4.28116 13.7185C5.57949 15.0174 7.15255 15.6666 9.00033 15.666Z"
                                fill="currentColor"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>


        <div class="card mb-3 mb-sm-4">
            <div class="card-header">
                <h3 class="fs-18">{{ translate('Push_Notification') }}</h3>
                <p class="mb-0 fs-12">
                    {{ translate('here_you_setup_your_all_push_notification') }}.
                </p>
            </div>
            <div class="card-body">
                <div class="row g-4 align-items-center justify-content-between mb-4">
                    <div class="col-auto">
                        <div class="table-responsive w-auto overflow-y-hidden">
                            @php($language = $language->value ?? null)
                            @php($default_lang = 'en')
                            @php($default_lang = json_decode($language)[0])
                            <div class="position-relative nav--tab-wrapper">
                                <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                    @foreach (json_decode($language) as $lang)
                                        <li class="nav-item" role="presentation">
                                            <a class="nav-link {{ $lang == $default_lang ? 'active' : '' }} lang-link"
                                               id="{{ $lang }}-link" data-bs-toggle="pill"
                                               href="javascript:" role="tab" aria-controls="pills-business"
                                               aria-selected="true">
                                                {{ ucfirst(Helpers::get_language_name($lang)) . '(' . strtoupper($lang) . ')' }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="nav--tab__prev">
                                    <button class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-left"></i>
                                    </button>
                                </div>
                                <div class="nav--tab__next">
                                    <button class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                @if(!request('type') || request('type') == 'customer')
                    @include("admin-views.push-notification.partials._customer-notification")
                @elseif(request('type') == 'vendor')
                    @include("admin-views.push-notification.partials._vendor-notification")
                @elseif(request('type') == 'deliveryman')
                    @include("admin-views.push-notification.partials._deliveryman-notification")
                @endif
            </div>
        </div>
    </div>

    <div class="modal fade" id="docsModal" tabindex="-1" aria-labelledby="docsModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">
                    <div class="d-flex flex-column gap-2">
                        <div class="text-center mb-1">
                            <img width="80" class="mb-4" loading="lazy" alt=""
                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/notice.png') }}">
                            <h4 class="lh-md text-capitalize">{{ translate('important_notice') }}!</h4>
                        </div>
                        <p class="mb-5">
                            {{ translate('to_include_specific_details_in_your_push_notification_message,_you_can_use_the_following_placeholders') }}
                            :
                        </p>

                        <ul class="d-flex flex-column px-4 gap-2 mb-4">
                            <li>
                                <strong>{{ '{deliveryManName}' }} : </strong>
                                {{ translate('the_name_of_the_delivery_person').'.'}}
                            </li>
                            <li>
                                <strong>{{ '{orderId}' }} : </strong>
                                {{ translate('the_unique_ID_of_the_order').'.'}}
                            </li>
                            <li>
                                <strong>{{ '{time}' }} :</strong>
                                {{ translate('the_expected_delivery_time').'.'}}
                            </li>
                            <li>
                                <strong>{{ '{userName}' }} :</strong>
                                {{ translate('the_name_of_the_user_who_placed_the_order').'.'}}
                            </li>
                        </ul>

                        <div class="mx-auto w-100 max-w-280">
                            <button class="btn btn-primary btn-block" data-bs-dismiss="modal">
                                {{ translate('got_it') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._notification-configuration")
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/notification.js') }}"></script>
@endpush

