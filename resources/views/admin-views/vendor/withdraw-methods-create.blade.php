@extends('layouts.admin.app')

@section('title', translate('Withdrawal_Methods'))

@section('content')
    <div class="content container-fluid">
        <h1 class="page-title text-capitalize mb-20">
            {{translate('Add_Withdraw_method')}}
        </h1>
        <form action="{{route('admin.third-party.withdraw-method.add')}}" method="POST" novalidate="novalidate">
            @csrf
            <div class="card card-body mb-20">
                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label for="method_name" class="form-label mb-2">{{ translate('Method_Name') }}</label>
                            <input type="text" class="form-control" name="method_name" id="method_name"
                                   placeholder="{{translate('Ex:_PayPal')}}" value=""  data-required-msg="{{translate('method_name_field_is_required')}}" required>
                        </div>
                        <div class="col-lg-6">
                            <label for="method_for" class="form-label mb-2">{{ translate('Method_For') }}</label>
                            <div class="min-h-40 d-flex align-items-sm-center flex-column flex-sm-row gap-sm-5 border rounded px-3 bg-white"
                                id="free-delivery-responsibility" data-default="admin">
                                <div class="form-check d-flex gap-2 my-2">
                                    <input class="form-check-input checkbox--input" type="checkbox" value="1"
                                        name="vendor_status" id="method_for_vendor" checked>
                                    <label class="form-check-label" for="method_for_vendor">
                                        {{ translate('Vendor') }}
                                    </label>
                                </div>
                                @if(getWebConfig(name: 'auction_feature_status'))
                                    <div class="form-check d-flex gap-2 my-2">
                                        <input class="form-check-input checkbox--input" type="checkbox" value="1"
                                               name="customer_status" id="method_for_customer">
                                        <label class="form-check-label" for="method_for_customer">
                                            {{ translate('Customer') }}
                                        </label>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <h2>{{ translate('Configure_Withdraw_Method_Fields') }}</h2>
                        <p class="mb-0">
                            {{ translate('add_and_customize_the_necessary_information_for_withdrawals') }}
                        </p>
                    </div>
                    <button class="btn btn-primary fs-12" id="add-more-field">
                        <i class="fi fi-sr-add d-flex"></i> {{translate('Add_New_Field')}}
                    </button>
                </div>
                <div id="custom-field-section" class="d-flex flex-column gap-3">
                    <div class="bg-section rounded-10 p-12 p-sm-20">
                        <div class="row g-3 align-items-end">
                            <div class="col-xl-3 col-lg-6">
                                <label class="form-label mb-2">{{translate('input_Field_Type')}} <span class="text-danger">*</span></label>
                                <select class="form-control custom-select" name="field_type[]"  data-required-msg="{{translate('input_field_type_is_required')}}" required>
                                    <option selected disabled>{{'--'. translate('select_Field_Type') . '--'}}</option>
                                    <option value="string">{{translate('string')}}</option>
                                    <option value="number">{{translate('number')}}</option>
                                    <option value="date">{{translate('date')}}</option>
                                    <option value="password">{{translate('password')}}</option>
                                    <option value="email">{{translate('email')}}</option>
                                    <option value="phone">{{translate('phone')}}</option>
                                </select>
                            </div>
                            <div class="col-xl-3 col-lg-6">
                                <div class="">
                                    <label class="form-label mb-2">{{translate('field_name')}} <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="field_name[]"
                                            data-required-msg="{{translate('field_name_is_required')}}"
                                            placeholder="{{translate('type_field_name')}}" value="" required>
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-6">
                                <div class="">
                                    <label class="form-label mb-2">{{translate('placeholder_text')}} <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="placeholder_text[]"
                                            data-required-msg="{{translate('placeholder_field_is_required')}}"
                                            placeholder="{{translate('type_placeholder_text')}}" value="" required>
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-6">
                                <div class="d-flex gap-2 form-control min-h-40">
                                    <input class="form-check-input checkbox--input" type="checkbox" value="1" name="is_required[0]" id="flex-check-default--0" checked>
                                    <label class="text-muted" for="flex-check-default--0">
                                        {{translate('this_field_required')}}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex mt-3">
                <div class="d-flex align-items-center gap-2">
                    <input class="form-check-input checkbox--input mt-0" type="checkbox" value="1" name="is_default" id="flex-check-default-method">
                    <label class="form-check-label" for="flex-check-default-method">
                        {{translate('default_method')}}
                    </label>
                </div>
            </div>

            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                        {{ translate('reset') }}
                    </button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4 demo_check">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
    <span id="get-add-filed-text"
          data-input-filed="{{translate('input_field_type')}}"
          data-string="{{translate('string')}}"
          data-number="{{translate('number')}}"
          data-date="{{translate('date')}}"
          data-password="{{translate('password')}}"
          data-email="{{translate('email')}}"
          data-phone="{{translate('phone')}}"
          data-field-name="{{translate('field_name')}}"
          data-placeholder-text="{{translate('placeholder_text')}}"
          data-required="{{translate('this_field_required')}}"
          data-remove="{{translate('remove')}}"
          data-reached-maximum="{{translate('reached_maximum')}}"
          data-confirm="{{translate('ok')}}"
    >
    </span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/admin/withdraw-method.js')}}"></script>
@endpush
