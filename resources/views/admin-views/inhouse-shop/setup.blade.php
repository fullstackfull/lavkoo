@extends('layouts.admin.app')

@section('title', translate('Others'))

@section('content')
    <div class="content container-fluid">
        <div class="row g-3 align-items-center mb-3">
            <div class="col-md-12">
                <h1 class="mb-3 sm-sm-20">
                    {{ translate('In-house_Shop') }}
                </h1>
                @include("admin-views.inhouse-shop._inhouse-shop-menu")
            </div>
        </div>

        @if ($minimumOrderAmountStatus || $free_delivery_status)
            <div class="mt-3">
                <form action="{{ route('admin.business-settings.inhouse-shop-setup') }}" method="POST"
                      enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                    @csrf

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="mb-sm-20 mb-3">
                                <h2>{{ translate('Order_Setup') }}</h2>
                                <p class="fs-12 mb-0">
                                    {{ translate('here_you_setup_order_amount_and_conditions_for_your_customer.') }}
                                </p>
                            </div>
                            <div class="bg-section rounded-8 p-12 p-sm-20">
                                <div class="row g-3">
                                    @if ($minimumOrderAmountStatus)
                                        <div class="col-lg-4 col-mg-6">
                                            <div class="form-group">
                                                <label class="form-label" for="minimum_order_amount">
                                                    {{ translate('minimum_order_amount') }} {{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                          data-bs-placement="top"
                                                          aria-label="{{ translate('set_a_certain_amount_below_that_customer_can_not_place_any_order') }}"
                                                          data-bs-title="{{ translate('set_a_certain_amount_below_that_customer_can_not_place_any_order') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <input type="number" min="0" class="form-control" step="any"
                                                       name="minimum_order_amount" id="minimum_order_amount"
                                                       placeholder="{{ translate('ex') }}: {{ '10' }}"
                                                       value="{{ usdToDefaultCurrency(amount: $minimumOrderAmount['value']) }}">
                                            </div>
                                        </div>
                                    @endif

                                    @if($freeDeliveryStatus)
                                        <div class="col-lg-4 col-mg-6">
                                            <div class="form-group">
                                                <label class="form-label"
                                                       for="free_delivery_over_amount">
                                                    {{ translate('free_Delivery_Over_Amount') }}
                                                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                          data-bs-placement="top"
                                                          aria-label="{{ translate('set_the_minimum_order_value_required_for_free_delivery') }}"
                                                          data-bs-title="{{ translate('set_the_minimum_order_value_required_for_free_delivery') }}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                </label>
                                                <input type="number" min="0" class="form-control" step="any"
                                                       name="free_delivery_over_amount"
                                                       id="free_delivery_over_amount"
                                                       placeholder="{{ translate('ex') }}: 10"
                                                       value="{{ usdToDefaultCurrency(amount: $freeDeliveryOverAmount['value'] ) }}">
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                                <div
                                    class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                                    <button type="reset"
                                            class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                                        <i class="fi fi-sr-disk"></i>
                                        {{ translate('save_information') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        @endif

    </div>
    @include("layouts.admin.partials.offcanvas._inhouse-shop-others-setup")
@endsection
