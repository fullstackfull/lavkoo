@extends('layouts.admin.app')

@section('title', translate('add_Offline_Payment_Method'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
            <div class="">
                <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/3rd-party.png')}}" alt="">
                    {{translate('add_Offline_Payment_Method')}}
                </h2>
                <a href="{{ route('admin.third-party.offline-payment-method.index') }}" class="d-flex mt-2 gap-1 align-items-center text-decoration-none">
                    <i class="fi fi-rr-arrow-small-left mt-1 fs-5"></i>
                    <span class="text text-capitalize">{{translate('Back_to_Offline_Payment_Mathods')}}</span>
                </a>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#getInformationModal">
                <i class="fi fi-sr-eye"></i>
                {{translate('Section_view')}}
            </button>
        </div>

        <form action="{{ route('admin.third-party.offline-payment-method.add') }}" method="POST" id="payment-method-offline" class="" novalidate>
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
                        <label for="method_name" class="form-label text-capitalize">{{ translate('payment_method_name') }}</label> <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="add payment method name" data-bs-title="add payment method name">
                            <i class="fi fi-sr-info"></i>
                        </span>
                        <input type="text" class="form-control" placeholder="{{ translate('ex').': '.translate('bkash') }}"
                               data-required-msg="{{ translate('method_name_is_required') }}"
                               name="method_name" required>
                    </div>

                    <div class="input-fields-section" id="input-fields-section">
                        @php($inputFieldsRandomNumber = rand())
                        <div class="p-12 p-sm-20 bg-section rounded" id="{{ $inputFieldsRandomNumber }}">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="input_name" class="form-label text-capitalize">{{ translate('input_field_Name') }}</label>
                                        <input type="text" name="input_name[]" class="form-control" placeholder="{{ translate('ex').': '.translate('bank_Name') }}"  data-required-msg="{{ translate('input_field_name_is_required') }}" >
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="input_data" class="form-label">{{ translate('input_data') }}</label>
                                        <input type="text" name="input_data[]" class="form-control" placeholder="{{ translate('ex').': '.translate('AVC_bank') }}"  data-required-msg="{{ translate('input_data_is_required') }}" >
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
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap mb-3">
                        <div class="">
                            <h3>{{translate('Information_Required_From_Customer')}}</h3>
                            <p class="fs-12">{{translate('add_relevant_input_fields_for_customers_to_fill-up_after_completing_the_offline_payment').' . '. translate('you_can_add_multiple_input_fields_&_place_holders_and_define_them_as_‘Is_Required’,_so_customers_cannot_complete_offline_payment_without_adding_that_information').'.'}}</p>
                        </div>

                        <a href="javascript:" id="add-customer-input-fields-group" class="btn btn-primary text-nowrap btn-sm text-capitalize">
                            <i class="fi fi-sr-add"></i> {{ translate('add_new_field') }}
                        </a>
                    </div>


                    @php($customerInputFieldsRandomNumber = rand())
                    <div class="customer-input-fields-section" id="customer-input-fields-section">
                        <div class="p-12 p-sm-20 bg-section rounded" id="{{ $customerInputFieldsRandomNumber }}">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize">{{ translate('input_field_Name') }}</label>
                                        <input type="text" name="customer_input[]" class="form-control" placeholder="{{ translate('ex').': '.translate('payment_By') }}" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="customer_placeholder" class="form-label">{{ translate('placeholder') }}</label>
                                        <input type="text" name="customer_placeholder[]" class="form-control" placeholder="{{ translate('ex').': '.translate('enter_name') }}" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex justify-content-between gap-2 h-100">
                                        <div class="form-check text-start mb-2 align-self-end">
                                            <label class="form-check-label text-dark" for="{{ $customerInputFieldsRandomNumber+1 }}">
                                                <input type="checkbox" class="form-check-input" value="1" id="{{ $customerInputFieldsRandomNumber+1 }}" name="is_required[0]"> {{ translate('is_required').'?' }}
                                            </label>
                                        </div>

                                        <a class="btn btn-danger delete icon-btn remove-input-fields-group" title="{{translate('delete')}}"  data-id="{{ $customerInputFieldsRandomNumber }}">
                                            <i class="fi fi-rr-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-3 mt-3">
                <button type="reset" class="btn btn-secondary">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fi fi-sr-disk"></i> {{ translate('Save Information') }}
                </button>
            </div>
        </form>
    </div>
    <div class="modal fade" id="getInformationModal" tabindex="-1" aria-labelledby="getInformationModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">
                    <div>
                        <div class="swiper instruction-carousel pb-3">
                            <div class="swiper-wrapper">
                                <div class="swiper-slide">
                                    <div class="d-flex flex-column align-items-center gap-2">
                                        <img width="80" class="mb-3"
                                             src="{{dynamicAsset(path: 'public/assets/back-end/img/delivery2.png')}}" loading="lazy"
                                             alt="">
                                        <h4 class="lh-md mb-3 text-capitalize">{{translate('create_your_custom_offline_payment_method')}}</h4>
                                        <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                            <li>
                                                {{translate('for_a_personalised_payment_experience').'!'}}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="d-flex flex-column align-items-center gap-2">
                                        <h4 class="lh-md mb-3 text-capitalize">{{translate('how_does_offline_payment_method_work').'?'}}</h4>
                                        <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                            <li>
                                                {{translate('step').' '.'1'.' :'.translate('add').' ‘'.translate('Payment_Information').'’'}}
                                            </li>
                                            <li>{{translate('step').' '.'2'.' :'.translate('click').' ‘ +'.translate('Add_New_Field').'’'.translate('for_more_information').'['.translate('according_to_your_payment_method').']'}}</li>
                                            <li>{{translate('step').' '.'3'.' :'.translate('add').' ‘'.translate('Required_Information_from_Customer').'’ '.'['.translate('that_you_need_to_verify_according_to_your_payment_method').']'}}</li>
                                            <li>{{translate('step').' '.'4'.' :'.translate('click').' ‘ +'.translate('Add_New_Field').'’'.translate('for_more_information').'['.translate('according_to_your_payment_method').']'}}</li>
                                            <li>{{translate('step').' '.'5'.' :'.translate('mark_the_check_box_if_the_field_is_required')}}</li>
                                            <li>{{translate('step').' '.'6'.' :'.translate('click').' ‘'.translate('submit').'’ '.translate('to_save_the_changes')}}</li>

                                        </ul>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="d-flex flex-column align-items-center gap-2">
                                        <h4 class="lh-md mb-3 text-capitalize">{{translate('important_note')}}<i></i></h4>
                                        <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                            <li>{{translate('you_can_add_one_or_more_offline_payment_methods_for_your_customers')}}</li>
                                            <li>{{translate('when_a_customer_chooses_the_‘Offline Payment’_during_checkout_and_chooses_their_favorite_payment_method,_they_must_fill-up_all_the_required_information_to_confirm_payment').'.'}} </li>
                                            <li>{{translate('later_admin_will_review_the_offline_payment_manually_to_confirm_order_by_changing_the_Order_&_Payment_Status_from_order_details_page').'.'}}
                                            <li>{{translate('to_review_offline_payment:_Go_to_Order_Details_page_>_view_Payment_Information_>_Match_the_payment_information').'.'}}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="d-flex flex-column align-items-center gap-2 mb-4">
                                        <img width="80" class="mb-3"
                                             src="{{dynamicAsset(path: 'public/assets/back-end/img/confirmed.png')}}" loading="lazy"
                                             alt="">
                                        <h4 class="lh-md mb-3 text-capitalize">{{translate('the_two-in-one_benefits_of_‘Offline_Payment_Method’_Feature').':'}}</h4>
                                        <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                            <li>{{translate('get_paid_from_customers')}}</li>
                                            <li>{{translate('introduce_more_convenient_payment_methods_for_customersEnjoy').'!'}}</li>
                                        </ul>
                                        <button type="button" class="btn btn-primary px-10 mt-3 text-capitalize" data-bs-dismiss="modal">{{ translate('got_it') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="instruction-pagination-custom my-2"></div>
                    </div>
                </div>
            </div>
        </div>
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

