@extends('layouts.admin.app')

@section('title', translate('Digital_payment'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/3rd-party.png') }}" alt="">
                {{ translate('Payment & Withdraw Methods Setup') }}
            </h2>
        </div>

        @include('admin-views.third-party._third-party-payment-method-menu')

        {{-- A gateway that is switched on and cannot take a payment.

             Credentials live as two separate blobs — live_values and test_values — and each
             controller reads only the one matching the row's mode. So a fully filled-in, green
             gateway on this page refuses every payment at checkout when the keys were typed into
             the mode that is switched off, and nothing on the form could ever say so. --}}
        @if (!empty($brokenGateways))
            <div class="alert alert-danger">
                <strong>{{ translate('these_gateways_are_switched_on_and_cannot_take_a_payment') }}:</strong>
                <ul class="mb-0 mt-2 fs-12">
                    @foreach ($brokenGateways as $gateway)
                        <li><strong>{{ $gateway['gateway'] }}</strong> — {{ $gateway['verdict'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($rehearsingGateways))
            <div class="alert alert-warning fs-12">
                {{ translate('these_gateways_are_live_on_your_checkout_in_test_mode_so_no_money_moves') }}:
                <strong>{{ implode(', ', $rehearsingGateways) }}</strong>.
            </div>
        @endif

        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded mb-3">
            <div class="d-flex gap-2 align-items-center mb-1">
                <i class="fi fi-sr-info text-warning"></i>
                <span>
                   {{ translate('here_you_can_configure_payment_gateways_by_obtaining_the_necessary_credentials') }} ({{ translate('e.g., _api_keys') }}) {{ translate('from_each_respective_payment_gateway_platform') }}.
                </span>
            </div>
            <ul class="m-0 ps-3">
                <li>{{ translate('to_use_digital_payments,_you_need_to_set_up_at_least_one_payment_method') }}</li>
                <li>{{ translate('to_make_available_these_payment_options,_you_must_enable_the_digital_payment_option_from') }}
                    <a class="text-underline" href="{{ route('admin.business-settings.web-config.index') }}" target="_blank">
                        {{ translate('Business_Information') }}
                    </a>
                    {{ translate('page') }}
                </li>
                <li>
                    {{ translate('If the last payment method is turned off, the auction entry fee will be disabled and customers can bid for free.') }}
                </li>
            </ul>
        </div>

        <?php
          $totalSupportedCurrencies = 0;
          if(isset($paymentGatewaysList)) {
              foreach ($paymentGatewaysList as $gatewayItem) {
                  if ($gatewayItem['total_supported_currencies'] > 0) {
                      $totalSupportedCurrencies = 1;
                      break;
                  }
              }
          }
        ?>

        @if($totalSupportedCurrencies <= 0)
            <div class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
                <i class="fi fi-sr-triangle-warning text-danger"></i>
                <span>
                {{ translate('currently_no_payment_gateway_supported_your_currency_active_at_least_one_gateway_that_support_your_currency._to_change_currency_setup_visit') }}
                <a href="{{ route('admin.system-setup.currency.view') }}" class="text-underline" target="_blank">
                    {{ translate('Currency') }}
                </a> {{ translate('page') }}
            </span>
            </div>
        @endif

        @if($paymentGatewayPublishedStatus)
            <div class="card mb-3">
                <div class="align-items-center card-body d-flex flex-wrap gap-3 justify-content-between">
                    <h4 class="text-danger bg-transparent m-0">
                        {{ translate('your_current_payment_settings_are_disabled,because_you_have_enabled_payment_gateway_addon').' '.translate('To_visit_your_currently_active_payment_gateway_settings_please_follow_the_link').'.' }}
                    </h4>
                    <a href="{{ !empty($paymentUrl) ? $paymentUrl : '' }}" class="btn btn-outline-primary">
                        <i class="fi fi-sr-settings"></i>
                        {{ translate('settings') }}
                    </a>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-3">
                    <h3 class="mb-0">
                        {{ translate('Digital_Payment_Methods_List') }}
                    </h3>

                    <div class="form-group mb-0 flex-grow-1 max-w-300 min-w-100-mobile">
                        <div class="input-group">
                            <input type="search" id="payment-method-search" class="form-control" placeholder="{{ translate('search_by_payment_method_name') }}">
                            <div class="input-group-append search-submit">
                                <button type="submit">
                                    <i class="fi fi-rr-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row gy-3" id="payment-gateway-cards">
                    @foreach($paymentGatewaysList as $key=> $gateway)
                        <div class="col-md-6 payment-gateway-cards">
                            <div class="card shadow-2">
                                @php($mode = $gateway->live_values['mode'] ?? '')
                                <div class="card-body d-flex gap-3 justify-content-between align-items-center">
                                    <h4 class="text-capitalize mb-0 d-flex flex-wrap gap-2 align-items-center">
                                        {{ str_replace('_',' ',$gateway->key_name) }}
                                        <span class="badge {{ $mode == 'test' ? 'text-bg-info badge-info' : 'text-bg-success badge-success'}}">
                                            {{ $mode ?? 'Test' }}
                                        </span>

                                        <?php
                                            $gatewayReadyToUse = 1;
                                            foreach ($gateway['live_values'] as $liveValues) {
                                                if (empty($liveValues) && $liveValues != 0) {
                                                    $gatewayReadyToUse = 0;
                                                }
                                            }
                                        ?>

                                        @if(!$gatewayReadyToUse)
                                            <span class="badge text-bg-danger badge-danger">
                                                {{ translate('Not_Configured') }}
                                            </span>
                                        @endif
                                    </h4>

                                    <div class="d-flex gap-3 align-items-center">
                                        @php($additionalData = $gateway['additional_data'] != null ? json_decode($gateway['additional_data']) : [])
                                        <?php
                                            $gatewayImgPath = dynamicAsset(path: 'public/assets/back-end/img/modal/payment-methods/' . $gateway->key_name . '.png');
                                            if (!empty($additionalData) && $additionalData?->gateway_image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image))) {
                                                $gatewayImgPath = $additionalData->gateway_image ? dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image) : $gatewayImgPath;
                                            }
                                        ?>


                                        @if(($gateway->is_active == 0 && $gateway->is_enabled_to_use) || ($gateway->is_active == 1))
                                            @if(!$gatewayReadyToUse)
                                                <label class="switcher"
                                                       data-bs-toggle="offcanvas"
                                                       data-bs-target="#offcanvas-{{ $gateway->key_name }}">
                                                    <input class="switcher_input"
                                                        type="checkbox" value="1" name="status" disabled>
                                                    <span class="switcher_control"></span>
                                                </label>
                                            @else
                                                <form action="{{ route('admin.third-party.payment-method.payment-status') }}" method="post"
                                                      id="payment-{{ $gateway->key_name}}-status-form">
                                                    @csrf
                                                    <input name="key_name" value="{{ $gateway->key_name }}" hidden>
                                                    <label class="switcher" for="payment-{{ $gateway->key_name}}-status">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="status"
                                                            id="payment-{{ $gateway->key_name }}-status"
                                                            {{ $gateway['is_active'] == 1 ? 'checked' : '' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#payment-{{ $gateway->key_name}}-status-form"
                                                            data-on-image="{{ $gatewayImgPath }}"
                                                            data-off-image="{{ $gatewayImgPath }}"
                                                            data-on-title="{{ translate('want_to_Turn_ON_') }}{{str_replace('_',' ',strtoupper($gateway->key_name))}}{{ translate('_as_the_Digital_Payment_method').'?'}}"
                                                            data-off-title="{{ translate('want_to_Turn_OFF_') }}{{str_replace('_',' ',strtoupper($gateway->key_name))}}{{ translate('_as_the_Digital_Payment_method').'?'}}"
                                                            data-on-message="<p>{{ translate('if_enabled_customers_can_use_this_payment_method') }}</p>"
                                                            data-off-message="<p>{{ translate('if_disabled_this_payment_method_will_be_hidden_from_the_checkout_page') }}</p>">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            @endif
                                        @else
                                            <label class="switcher" data-bs-toggle="modal" data-bs-target="#gateway-modal-{{ $gateway['key_name'] }}">
                                                <input class="switcher_input" type="checkbox" name="status" value="1" disabled
                                                       id="{{ $gateway->key_name}}" {{ $gateway['is_active'] == 1?'checked':''}}
                                                       data-modal-id="toggle-modal"
                                                       data-toggle-id="{{ $gateway->key_name}}"
                                                       data-on-image="{{ $gatewayImgPath }}"
                                                       data-off-image="{{ $gatewayImgPath }}"
                                                       data-on-title="{{ translate('want_to_Turn_ON_') }}{{str_replace('_',' ',strtoupper($gateway->key_name))}}{{ translate('_as_the_Digital_Payment_method').'?'}}"
                                                       data-off-title="{{ translate('want_to_Turn_OFF_') }}{{str_replace('_',' ',strtoupper($gateway->key_name))}}{{ translate('_as_the_Digital_Payment_method').'?'}}"
                                                       data-on-message="<p>{{ translate('if_enabled_customers_can_use_this_payment_method') }}</p>"
                                                       data-off-message="<p>{{ translate('if_disabled_this_payment_method_will_be_hidden_from_the_checkout_page') }}</p>">
                                                <span class="switcher_control" data-ontitle="{{ translate('on') }}" data-offtitle="{{ translate('off') }}"></span>
                                            </label>
                                        @endif

                                        <button class="btn btn-outline-warning btn-outline-warning-dark icon-btn"
                                                data-bs-toggle="offcanvas"
                                                data-bs-target="#offcanvas-{{ $gateway->key_name }}">
                                            <i class="fi fi-sr-settings"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if(!(($gateway->is_active == 0 && $gateway->is_enabled_to_use) || ($gateway->is_active == 1 && $gateway->must_required_for_currency != 1)))
                            <div class="modal fade" id="gateway-modal-{{ $gateway['key_name'] }}" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content shadow-lg">
                                        <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                                            <button type="button" class="btn-close border-0" data-bs-dismiss="modal" aria-label="Close">
                                                <i class="tio-clear"></i>
                                            </button>
                                        </div>
                                        <div class="modal-body px-4 px-sm-5 pt-0">
                                            <div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
                                                <div class="toggle-modal-img-box d-flex flex-column justify-content-center align-items-center mb-3 position-relative">
                                                    @if($gateway['is_active'] == 1)
                                                        <img src="{{ getValidImage(path: 'payment-gateway-off.png', type: 'banner', source: dynamicAsset('public/assets/back-end/img/modal/payment-gateway-off.png')) }}" class="status-icon"  alt="" width="80"/>
                                                    @else
                                                        <img src="{{ getValidImage(path: 'payment-gateway-on.png', type: 'banner', source: dynamicAsset('public/assets/back-end/img/modal/payment-gateway-on.png')) }}" class="status-icon"  alt="" width="80"/>
                                                    @endif
                                                    <img src="" alt="" />
                                                </div>
                                                <h3 class="modal-title">
                                                    {{ translate('Are_you_sure') }}, {{ translate('want_to_turn_'. ($gateway['is_active'] == 1 ? 'Off' : 'ON').'_'.$gateway['key_name'] .'_as_the_Digital_Payment_method') }}?
                                                </h3>
                                                <div class="text-center">
                                                    {{ translate('If_you_enable_this_payment_gateway_please_check_in_currency_settings_that_currency_support_this_gateway_or_not') }}!
                                                </div>
                                                <div class="text-center py-3">
                                                    <a class="text-underline font-bold" href="{{ route('admin.system-setup.currency.view') }}">
                                                        {{ translate('Go_to_currency_settings') }}
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-center gap-3 mt-3">
                                                <button type="button" class="btn btn-primary min-w-120" data-bs-dismiss="modal">
                                                    {{ translate('ok') }}
                                                </button>
                                                <button type="button" class="btn btn-danger min-w-120" data-bs-dismiss="modal">
                                                    {{ translate('cancel') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                        <div class="empty-state-for-payment d-none">
                            @include('layouts.admin.partials._empty-state',['text'=>'no_payment_method_found'],['image'=>'offline-payment', 'width' => 60])
                        </div>
                </div>
            </div>
        </div>

        @foreach($paymentGatewaysList as $key=> $gateway)
            @include("admin-views.third-party.payment-method._payment-gateways-offcanvas", ['gateway' => $gateway])
        @endforeach
    </div>

    @include("layouts.admin.partials.offcanvas._digital-payment-setup")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/payment-method-setup.js') }}"></script>
    <script>
        'use strict';
        @if($paymentGatewayPublishedStatus)
            let paymentGatewayCards = $('#payment-gateway-cards');
            paymentGatewayCards.find('input').each(function () {
                $(this).attr('disabled', true);
            });
            paymentGatewayCards.find('select').each(function () {
                $(this).attr('disabled', true);
            });
            paymentGatewayCards.find('.switcher_input').each(function () {
                $(this).removeAttr('checked', true);
            });
            paymentGatewayCards.find('button').each(function () {
                $(this).attr('disabled', true);
            });
        @endif
    </script>
@endpush
