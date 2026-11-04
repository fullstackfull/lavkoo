<div class="offcanvas-body p-3 overflow-auto flex-grow-1">
    <div class="bg-light p-3 rounded mb-3">

        <input type="hidden" name="withdraw_request_id" value="{{ $withdrawRequest['id'] }}">

        @if (count($vendorWithdrawMethods) <= 0)
            <div class="d-flex gap-2 alert alert-soft-warning mb-4" role="alert">
                <i class="fi fi-sr-info"></i>
                <p class="fs-12 mb-0 text-dark">
                    {{ translate('you_can_send_a_withdrawal_request_to_the_admin_using_the_available_methods_.') }}
                    {{ translate('to_avoid_entering_details_each_time,') }}
                    {{ translate('set_up_your_payment_info_on_the_payment_options_page_.') }}
                    <a href="{{ route('vendor.shop.payment-information.index') }}" class="text-underline font-weight-bold"
                        target="_blank">
                        {{ translate('Payment_Options_Page') }}
                    </a>
                </p>
            </div>
        @endif

        <div class="form-group">
            <label class="form-label text-dark">
                {{ translate('Select_Withdrawal_Method') }}
                <span class="text-danger">*</span>

                <span class="tooltip-icon" data-toggle="tooltip" data-placement="top"
                    aria-label="{{ translate('select_a_withdrawal_method_to_set_up_your_payment_details_for_sending_withdrawal_requests_to_the_admin_.') }}"
                    data-title="{{ translate('select_a_withdrawal_method_to_set_up_your_payment_details_for_sending_withdrawal_requests_to_the_admin_.') }}" title="">
                    <i class="fi fi-sr-info"></i>
                </span>
            </label>

            <select name="withdraw_method"
                class="form-control js-select2-custom vendor-withdrawal-method-update with_note"
                data-note-id="note-withdrawal-method" required
                data-route="{{ route('vendor.business-settings.withdraw.render-withdraw-method-infos') }}"
                data-current-request="{{ $withdrawRequest['id'] }}"
                data-container="#withdraw-request-method-filed-{{ $withdrawRequest['id'] }}">
                <option value="" selected disabled>{{ translate('Select') }}</option>
                @if (count($vendorWithdrawMethods) > 0)
                    <optgroup label="My Methods">
                        @foreach ($vendorWithdrawMethods as $vendorWithdrawMethod)
                            <option value="{{ $vendorWithdrawMethod['id'] }}" data-type="custom"
                                @if($withdrawRequest['request_method_type'] == 'vendor' && $withdrawRequest['withdrawal_method_id'] != null)
                                    {{ $vendorWithdrawMethod['id'] == $withdrawRequest['withdrawal_method_id'] ? 'selected' : '' }}
                                @endif
                            >
                                {{ $vendorWithdrawMethod['method_name'] }}
                            </option>
                        @endforeach
                    </optgroup>
                @endif
                @if (count($withdrawalMethods) > 0)
                    @if (count($vendorWithdrawMethods) > 0)
                        <optgroup label="Others">
                    @endif
                    @foreach ($withdrawalMethods as $withdrawalMethod)
                        <option value="{{ $withdrawalMethod['id'] }}" data-type="pre-defined"
                            @if(($withdrawRequest['request_method_type'] == 'system' || ($withdrawRequest['request_method_type'] != 'vendor' && $withdrawRequest['request_method_type'] != null)) && $withdrawRequest['withdrawal_method_id'] != null)
                                {{ $withdrawalMethod['id'] == $withdrawRequest['withdrawal_method_id'] ? 'selected' : '' }}
                            @endif
                            >
                            {{ $withdrawalMethod['method_name'] }}
                        </option>
                    @endforeach
                    @if (count($vendorWithdrawMethods) > 0)
                        </optgroup>
                    @endif
                @endif
            </select>

            <div id="note-withdrawal-method" class="d-none">
                <li class="select2-custom-note">
                    <div class="d-flex gap-2 alert alert-soft-warning mb-0" role="alert">
                        <i class="fi fi-sr-info"></i>
                        <p class="fs-12 mb-0 text-dark">
                            {{ translate('save_your_payments_info_for_easy_use,_setup_from_here') }}
                            <a href="{{ route('vendor.shop.payment-information.index') }}"
                                class="text-underline font-weight-bold" target="_blank">
                                {{ translate('payments_setup.') }}
                            </a>
                        </p>
                    </div>
                </li>
            </div>
        </div>

        <div class="" id="withdraw-request-method-filed-{{ $withdrawRequest['id'] }}">
            @foreach ($vendorWithdrawMethods as $vendorWithdrawMethod)
                @if($withdrawRequest['request_method_type'] == 'vendor' && $withdrawRequest['withdrawal_method_id'] != null && $vendorWithdrawMethod['id'] == $withdrawRequest['withdrawal_method_id'])
                    <?php
                        $methodInfo = is_array($vendorWithdrawMethod->method_info) ? $vendorWithdrawMethod->method_info : json_decode($vendorWithdrawMethod->method_info, true);
                    ?>
                    <div class="form-group">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex gap-2 align-items-center mb-2">
                                    <img height="18"
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/wallet-svg-icon.svg') }}"
                                        alt="{{ $vendorWithdrawMethod['withdraw_method']['method_name'] }}">
                                    <h5 class="m-0">{{ $vendorWithdrawMethod['withdraw_method']['method_name'] }}
                                    </h5>
                                </div>

                                <table class="table-borderless text--black" role="presentation">
                                    <tbody>
                                        @foreach ($vendorWithdrawMethod['withdraw_method']?->method_fields as $methodField)
                                            <tr class="bg-transparent">
                                                <td class="p-1">{{ translate($methodField['input_name']) }}</td>
                                                <td class="p-1">:</td>
                                                <td class="p-1">
                                                    {{ $methodInfo[$methodField['input_name']] }}
                                                    <input type="hidden"
                                                        name="method_info[{{ $methodField['input_name'] }}]"
                                                        value="{{ $methodInfo[$methodField['input_name']] }}"
                                                        {{ $methodField['is_required'] ? 'required' : '' }}>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
            @foreach($withdrawalMethods as $withdrawalMethod)
                @if(($withdrawRequest['request_method_type'] == 'system' || ($withdrawRequest['request_method_type'] != 'vendor' && $withdrawRequest['request_method_type'] != null)) &&
                    $withdrawalMethod['id'] == $withdrawRequest['withdrawal_method_id'])
                    <?php
                        $methodInfo = is_array($withdrawRequest?->withdrawal_method_fields) ? $withdrawRequest?->withdrawal_method_fields : json_decode($withdrawRequest?->withdrawal_method_fields, true);
                    ?>
                    @foreach ($withdrawalMethod?->method_fields as $methodField)
                        <div class="form-group">
                            <label class="form-label text-dark">
                                {{ translate($methodField['input_name']) }}
                                <span class="text-danger">{{ $methodField['is_required'] ? '*' : '' }}</span>
                            </label>
                            <input type="{{ $methodField['input_type'] == 'phone' ? 'tel' : $methodField['input_type'] }}"
                                   class="form-control"
                                   placeholder="{{ translate($methodField['placeholder']) }}"
                                   name="method_info[{{ $methodField['input_name'] }}]"
                                   value="{{ $methodInfo[$methodField['input_name']] ?? '' }}"
                                {{ $methodField['is_required'] ? 'required' : '' }}>
                        </div>
                    @endforeach
                @endif
            @endforeach

            <div class="form-group">
                <label class="form-label text-dark">
                    {{ translate('Withdraw_Amount ') }}
                    ({{ getCurrencySymbol() }})
                    <span class="text-danger">*</span>
                </label>
                    <?php
                    $withdrawalTotalAmount=($vendorWallet?->total_earning ?? 0) + $withdrawRequest['amount'];
                    ?>
                <input type="number" class="form-control" name="amount" step="any" min=".01" required
                       placeholder="{{ translate('Ex') }}: {{ usdToDefaultCurrency(amount: $withdrawalTotalAmount) }}"
                       max="{{ usdToDefaultCurrency(amount: $withdrawalTotalAmount) }}"
                       value="{{ usdToDefaultCurrency(amount: $withdrawRequest['amount']) }}">
            </div>
        </div>
    </div>
</div>
