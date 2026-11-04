@extends('layouts.admin.app')

@section('title', translate('offline_Payment'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/3rd-party.png')}}" alt="">
                {{translate('payment_methods_setup')}}
            </h2>
        </div>

        @include('admin-views.third-party._third-party-payment-method-menu')

        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded mb-3">
            <div class="d-flex gap-2 align-items-center mb-1">
                <i class="fi fi-sr-info text-warning"></i>
                <span>
                    {{ translate('in_this_section,_you_can_add_offline_payment_methods_to_make_them_available_as_offline_payment_options_for_the_customers') }}
                </span>
            </div>
            <ul class="m-0 ps-3">
                <li>{{ translate('to_make_available_these_payment_options,_you_must_enable_the_offline_payment_option_from') }} <a href="{{ route('admin.business-settings.web-config.index') }}" target="_blank">{{ translate('Business_Information') }}</a> {{ translate('page') }}</li>
                <li>{{ translate('to_use_offline_payments,_you_need_to_set_up_at_least_one_offline_payment_method') }}</li>
            </ul>
        </div>

        <div class="tab-content" id="nav-tabContent">
            <div class="tab-pane fade show active" id="nav-all" role="tabpanel" aria-labelledby="nav-all-tab">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                            <h3 class="">{{ translate('Offline_Payment_Methods_List') }}</h3>

                            <div class="d-flex gap-3 flex-wrap align-items-center">
                                <form action="{{ route('admin.third-party.offline-payment-method.index') }}" method="GET">
                                    <div class="form-group">
                                        <div class="input-group">
                                            <input type="search" value="{{ requestString('searchValue') }}" name="searchValue" class="form-control min-w-300" placeholder="{{ translate('Search_by_payment_method_name') }}">
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                                <a href="{{route('admin.third-party.offline-payment-method.add')}}" class="btn btn-primary"><i class="fi fi-sr-add"></i> {{ translate('add_New_Method') }}</a>
                            </div>
                        </div>

                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('SL') }}</th>
                                        <th>{{ translate('payment_Method_Name') }}</th>
                                        <th>{{ translate('payment_Info') }}</th>
                                        <th>{{ translate('required_Info_From_Customer') }}</th>
                                        <th class="text-center">{{ translate('status') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($methods as $key=>$method)
                                        <tr>
                                            <td>{{$methods->firstItem()+$key}}</td>
                                            <td>{{ $method->method_name }}</td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach ($method->method_fields as $item)
                                                        <div>{{ ucwords(str_replace('_',' ',$item['input_name'])) }} : {{ $item['input_data'] }}</div>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach ($method->method_informations as $item)
                                                    <div>
                                                        {{ ucwords(str_replace('_',' ',$item['customer_input'])) }}
                                                    </div>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <form action="{{route('admin.third-party.offline-payment-method.update-status')}}" method="post" id="method-status{{$method['id']}}-form" class="method-status-form">
                                                    @csrf
                                                    <input type="hidden" name="id" value="{{$method['id']}}">
                                                    <label class="switcher mx-auto" for="method-status{{$method['id']}}">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="status"
                                                            id="method-status{{$method['id']}}"
                                                            {{ $method->status == 1 ? 'checked':'' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#method-status{{$method['id']}}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/offline-payment.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/offline-payment.png') }}"
                                                            data-on-title = "{{translate('Turn_ON') }} {{$method['method_name'] }} {{ translate('Payment_Methods') }}"
                                                            data-off-title = "{{translate('Turn_Off') }} {{$method['method_name'] }} {{ translate('Payment_Methods') }}"
                                                            data-on-message = "<p>{{translate('if_enabled_customers_can_pay_through_different_payment_methods_outside_your_system')}}</p>"
                                                            data-off-message = "<p>{{translate('if_disabled_customers_can_only_pay_through_the_system_supported_payment_methods')}}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>

                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-center gap-2">
                                                    <a class="btn btn-outline-info icon-btn" title="Edit" href="{{route('admin.third-party.offline-payment-method.update', ['id'=>$method->id])}}">
                                                        <i class="fi fi-sr-pencil"></i>
                                                    </a>

                                                    <button class="btn btn-outline-danger icon-btn delete-data" title="{{translate('delete')}}" data-id="delete-method-name-{{ $method->id }}">
                                                        <i class="fi fi-rr-trash"></i>
                                                    </button>
                                                </div>

                                                <form action="{{route('admin.third-party.offline-payment-method.delete')}}" method="post" id="delete-method-name-{{ $method->id }}">
                                                    @csrf
                                                    <input type="hidden" value="{{ $method->id }}" name="id" required>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if ($methods->count() > 0)
                                <div class="p-3 d-flex justify-content-end">
                                    @php
                                        if (request()->has('status')) {
                                            $paginationLinks = $methods->links();
                                            $modifiedLinks = preg_replace('/href="([^"]*)"/', 'href="$1&status='.request('status').'"', $paginationLinks);
                                        } else {
                                            $modifiedLinks = $methods->links();
                                        }
                                    @endphp
                                    {!! $modifiedLinks !!}
                                </div>
                            @endif
                        </div>
                        @if ($methods->count() <= 0)
                            @include('layouts.admin.partials._empty-state', [
                                    'text' => 'no_payment_method_list',
                                    'image' => 'offline-payment',
                                    'width' => 60,
                                    'button' => true,
                                    'route' => route('admin.third-party.offline-payment-method.add'),
                                    'buttonText' => 'add_new_method'
                                ])
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @include("layouts.admin.partials.offcanvas._offline-payment-setup")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path:'public/assets/backend/admin/js/third-party/offline-payment.js') }}"></script>
@endpush
