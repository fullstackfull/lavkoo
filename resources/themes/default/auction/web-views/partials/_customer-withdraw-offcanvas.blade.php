@php
    $existingWithdraw = $existingWithdraw ?? null;
    $isEdit           = !empty($existingWithdraw);
    $withdrawAmount   = $withdrawAmount ?? null;
@endphp

<div class="offcanvas offcanvas-end" tabindex="-1"
     id="auction-customer-withdraw-offcanvas"
     aria-labelledby="auctionWithdrawOffcanvasLabel"
     style="width:420px;max-width:100%;">

    <div class="offcanvas-header border-bottom py-3 px-4">
        <h5 class="offcanvas-title fs-16 fw-semibold title-clr mb-0" id="auctionWithdrawOffcanvasLabel">
            {{ translate('Withdraw_Request') }}
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                aria-label="{{ translate('Close') }}"></button>
    </div>

    <form id="auction-withdraw-form"
          action="{{ route('auction.withdraw.store') }}"
          method="POST"
          class="d-flex flex-column flex-grow-1 overflow-hidden">
        @csrf

        @if($isEdit)
            <input type="hidden" name="existing_withdraw_id" value="{{ $existingWithdraw->id }}">
        @endif

        <input type="hidden" name="auction_product_id" value="{{ $auctionProduct->id ?? '' }}">

        <div class="offcanvas-body p-4 overflow-auto flex-grow-1">

            @if(empty($customerWithdrawalMethods) || $customerWithdrawalMethods->isEmpty())
                <div class="d-flex gap-2 alert alert-warning mb-0" role="alert">
                    <i class="fi fi-sr-info mt-1 flex-shrink-0"></i>
                    <p class="fs-12 mb-0 text-dark">
                        {{ translate('No_withdrawal_methods_are_currently_enabled_for_customers._Please_contact_admin.') }}
                    </p>
                </div>
            @else
                <div class="mb-3">
                    <label class="form-label fs-13 fw-semibold title-clr" for="auctionWithdrawMethodSelect">
                        {{ translate('Select_Withdrawal_Method') }}
                        <span class="text-danger">*</span>
                    </label>
                    <select id="auctionWithdrawMethodSelect"
                            name="withdraw_method_id"
                            class="form-select fs-13 js-auction-withdraw-method-select"
                            data-route="{{ route('auction.withdraw.method-fields') }}"
                            required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>{{ translate('Select') }}</option>
                        @foreach($customerWithdrawalMethods as $method)
                            <option value="{{ $method->id }}"
                                {{ $isEdit && $existingWithdraw->withdrawal_method_id == $method->id ? 'selected' : '' }}>
                                {{ $method->method_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Blade-rendered method fields (pre-rendered when editing, injected via AJAX otherwise) --}}
                <div id="auction-withdraw-dynamic-fields">
                    @if($isEdit && $existingWithdraw->withdrawalMethod)
                        @include('auction.web-views.partials._customer-withdraw-method-fields', [
                            'method'         => $existingWithdraw->withdrawalMethod,
                            'existingValues' => $existingWithdraw->withdrawal_method_fields ?? [],
                        ])
                    @endif
                </div>

                @if($withdrawAmount !== null)
                    <input type="hidden" name="amount" value="{{ $withdrawAmount }}">
                    <div class="card border-0 light-box rounded p-3 mt-1">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fs-13 title-semidark">{{ translate('Withdraw_Amount') }}</span>
                            <span class="fs-14 fw-semibold title-clr">{{ webCurrencyConverter(amount: $withdrawAmount) }}</span>
                        </div>
                    </div>
                @endif
            @endif

        </div>

        @if(!empty($customerWithdrawalMethods) && $customerWithdrawalMethods->isNotEmpty())
            <div class="border-top p-3 bg-white d-flex gap-2">
                <button type="button" class="btn btn-sm btn-secondary flex-grow-1 fs-12 fw-semibold" data-bs-dismiss="offcanvas">
                    {{ translate('Cancel') }}
                </button>
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1 fs-12 fw-semibold">
                    {{ $isEdit ? translate('Send_Request_Again') : translate('Send_Request') }}
                </button>
            </div>
        @endif

    </form>
</div>
