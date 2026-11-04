@extends('layouts.admin.app')

@section('title', translate('edit_Offline_Payment_Method'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
            <div class="">
                <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/3rd-party.png')}}" alt="">
                    {{translate('edit_Offline_Payment_Method')}}
                </h2>
                <a href="{{ route('admin.third-party.offline-payment-method.index') }}" class="d-flex mt-2 gap-1 align-items-center text-decoration-none">
                    <i class="fi fi-rr-arrow-small-left mt-1 fs-5"></i>
                    <span class="text text-capitalize">{{translate('Back_to_Offline_Payment_Mathods')}}</span>
                </a>
            </div>
        </div>

        @include('admin-views.third-party._third-party-payment-method-menu')

        <form action="{{ route('admin.third-party.offline-payment-method.update',[$method['id']]) }}" method="POST" id="payment-method-offline" class="form-advance-validation form-advance-inputs-validation">
            @csrf
            <div class="card mt-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap mb-3">
                        <div class="">
                            <h3>{{translate('payment_information')}}</h3>
                            <p class="fs-12">{{translate('choose_your_preferred_payment_method_such_as_bank,_mobile_wallet,_digital_cards,_etc').' . '.translate('that_customers_will_choose_from_and_add_relevant_input_fields_for_the_payment_method').'.'}}</p>
                        </div>

                        <a href="javascript:" id="add-input-fields-group" class="btn btn-primary btn-sm text-capitalize text-nowrap">
                            <i class="fi fi-sr-add"></i> {{ translate('add_new_field') }}
                        </a>
                    </div>

                    <div class="form-group p-12 p-sm-20 bg-section rounded">
                        <label for="method_name" class="form-label">{{ translate('payment_method_name') }}</label>
                        <input type="text" class="form-control" placeholder="{{ translate('ex').':'.translate('bkash') }}" name="method_name"  data-required-msg="{{ translate('method_name_is_required') }}"  value="{{ $method['method_name'] }}" >
                    </div>

                    <input type="hidden" name="id" value="{{ $method['id'] }}">
                    <div class="input-fields-section" id="input-fields-section">
                        @foreach ($method['method_fields'] as $key=>$item)
                            @php($inputFieldsRandomNumber = rand())
                            <div class="p-12 p-sm-20 bg-section rounded" id="{{ $inputFieldsRandomNumber }}">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="input_name" class="form-label">{{ translate('input_field_Name') }}</label>
                                            <input type="text" name="input_name[]" class="form-control"  placeholder="{{ translate('ex').':'.translate('bank_Name') }}" data-required-msg="{{ translate('input_field_name_is_required') }}"   value="{{ str_replace('_',' ',$item['input_name']) }} ">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="input_data" class="form-label">{{ translate('input_data') }}</label>
                                            <input type="text" name="input_data[]" class="form-control" placeholder="{{ translate('ex').':'.translate('AVC_bank') }}" data-required-msg="{{ translate('input_data_is_required') }}"  value="{{ $item['input_data'] }}">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <div class="d-flex justify-content-end">
                                                <button type="button" class="btn btn-danger icon-btn remove-input-fields-group" title="{{translate('delete')}}"  data-id="{{ $inputFieldsRandomNumber }}">
                                                    <i class="fi fi-rr-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap mb-3">
                        <div class="">
                            <h3>{{translate('required_information_from_Customer')}}</h3>
                            <p class="fs-12">{{translate('add_relevant_input_fields_for_customers_to_fill-up_after_completing_the_offline_payment').' . '. translate('you_can_add_multiple_input_fields_&_place_holders_and_define_them_as_‘Is_Required’,_so_customers_cannot_complete_offline_payment_without_adding_that_information').'.'}}</p>
                        </div>

                        <a href="javascript:" id="add-customer-input-fields-group" class="btn btn-primary btn-sm text-capitalize text-nowrap">
                            <i class="fi fi-sr-add"></i> {{ translate('add_new_field') }}
                        </a>
                    </div>

                    <div class="customer-input-fields-section" id="customer-input-fields-section">
                        @php($counter = count($method['method_informations']))
                        @foreach ($method['method_informations'] as $key=>$item)
                            @php($customerInputFieldsRandomNumber = rand())
                            <div class="p-12 p-sm-20 bg-section rounded {{ $loop->first ? '' : 'mt-3' }}" id="{{ $customerInputFieldsRandomNumber }}">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="form-label">{{ translate('input_field_Name') }}</label>
                                            <input type="text" name="customer_input[]" class="form-control" placeholder="{{ translate('ex').':'.translate('payment_By') }}"  required value="{{ str_replace('_',' ',$item['customer_input']) }}">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="customer_placeholder" class="form-label">{{ translate('place_Holder') }}</label>
                                            <input type="text" name="customer_placeholder[]" class="form-control" placeholder="{{ translate('ex').':'.translate('enter_name') }}" required value="{{ $item['customer_placeholder'] }}">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between gap-2 h-100">
                                            <div class="form-check text-start mb-2 align-self-end">
                                                <label class="form-check-label text-dark" for="{{ $customerInputFieldsRandomNumber+1 }}">
                                                    <input type="checkbox" class="form-check-input" id="{{ $customerInputFieldsRandomNumber+1 }}" name="is_required[{{ $key }}]" {{ (isset($item['is_required']) && $item['is_required']) == 1 ? 'checked':'' }}> {{ translate('is_required').'?' }}
                                                </label>
                                            </div>

                                            <a class="btn btn-danger delete icon-btn remove-input-fields-group" title="{{translate('delete')}}"  data-id="{{ $customerInputFieldsRandomNumber }}">
                                                <i class="fi fi-rr-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-3 mt-3">
                <button type="reset" class="btn btn-secondary">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn-primary"><i class="fi fi-sr-disk"></i> {{ translate('update_Information') }}</button>
            </div>
        </form>
    </div>
    <span id="get-add-input-field-text"
          data-input-field-name = "{{translate('input_field_Name')}}"
          data-input-field-name-placeholder = "{{translate('ex').':'.translate('bank_Name')}}"
          data-input-data = "{{translate('input_data')}}"
          data-input-data-placeholder = "{{translate('ex').':'.translate('AVC_bank')}}"
          data-delete-text = "{{translate('delete')}}"
    ></span>
    <span id="get-add-customer-input-field-text"
          data-input-field-name = "{{translate('input_field_Name')}}"
          data-input-field-name-placeholder = "{{translate('ex').':'.translate('payment_By')}}"
          data-input-placeholder = "{{translate('placeholder')}}"
          data-input-placeholder-placeholder = "{{translate('ex').':'.translate('enter_name')}}"
          data-delete-text = "{{translate('delete')}}"
          data-require-text = "{{translate('is_required').'?'}}"
    ></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/third-party/offline-payment.js') }}"></script>
@endpush
