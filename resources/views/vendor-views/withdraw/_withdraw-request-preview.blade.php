<div class="offcanvas-sidebar" id="withdrawInfoViewOffcanvas{{ $withdrawRequest['id'] }}">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white d-flex flex-column">

        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="m-0">{{ translate('Withdraw_Information') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;"
                    data-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>

        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="d-flex justify-content-between gap-3 mb-4">
                <div class="flex-grow-1 fs-16 fs-12-mobile">
                    <div class="d-flex gap-2 mb-2">
                        <span>{{ translate('Withdraw_Amount') }}</span> : <span
                            class="fw-medium text-dark">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawRequest['amount']), currencyCode: getCurrencyCode(type: 'default')) }}
                            </span>
                    </div>
                    <div class="d-flex gap-2">
                        <span>{{ translate('Request_Time') }} :</span>
                        <span class="text-dark">
                                {{ date('d M Y', strtotime($withdrawRequest->created_at)) }} | {{ date('h:i A', strtotime($withdrawRequest->created_at)) }}
                            </span>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    @if ($withdrawRequest->approved == 0)
                        <span class="px-2 py-1 rounded fs-12 fw-medium text-info bg-info bg-opacity-10">
                                {{ translate('Pending') }}
                            </span>
                    @elseif($withdrawRequest->approved == 1)
                        <span class="px-2 py-1 rounded fs-12 fw-medium text-success bg-success bg-opacity-10">
                                {{ translate('Pending') }}
                            </span>
                    @else
                        <span class="px-2 py-1 rounded fs-12 fw-medium text-danger bg-danger bg-opacity-10">
                                {{ translate('Pending') }}
                            </span>
                    @endif
                </div>
            </div>

            @php($withdrawalMethodFields = isset($withdrawRequest?->withdrawal_method_fields) && is_array($withdrawRequest?->withdrawal_method_fields) ? $withdrawRequest?->withdrawal_method_fields : json_decode($withdrawRequest?->withdrawal_method_fields ?? '', true))
            @if($withdrawalMethodFields && count($withdrawalMethodFields) > 0)
                <div class="p-12 p-sm-20 bg-section rounded-10 overflow-wrap-anywhere">
                    <div class="d-flex flex-column gap-3 overflow-wrap-anywhere">
                        @foreach($withdrawalMethodFields as $fieldKey => $field)
                            <div class="d-flex gap-2">
                                <div class="w-170 w-100px-mobile flex-shrink-0">
                                    {{ ucwords(str_replace('_', ' ', $fieldKey)) }} :
                                </div>
                                <span>{{ $field }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($withdrawRequest->approved == 1)
                <div class="bg-success bg-opacity-10 p-12 p-sm-20 rounded-10 mt-4">
                    <p class="text-dark mb-0">
                        <span class="text-success">{{ translate('Approve_Note') }} :</span>
                        <span>{{ $withdrawRequest?->transaction_note ?? 'N/a' }}</span>
                    </p>
                </div>
            @elseif($withdrawRequest->approved == 2)
                <div class="bg-danger bg-opacity-10 p-12 p-sm-20 rounded-10 mt-4">
                    <p class="text-dark mb-0">
                        <span class="text-danger">{{ translate('Denied_Note') }} :</span>
                        <span>{{ $withdrawRequest?->transaction_note ?? 'N/a' }}</span>
                    </p>
                </div>
            @endif
        </div>

        <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                @if ($withdrawRequest->approved == 0)
                    <button
                        data-alert-title="{{ translate('you_want_to_delete_this_withdrawal_request') }}?"
                        data-alert-text="{{ translate('once_deleted,_the_withdrawal_request_cannot_be_recovered_and_it_will_not_appear_in_your_withdrawal_request_method_list')}}."
                        data-toggle="tooltip" title="{{ translate('Remove Withdraw Request') }}"
                        class="btn bg-soft-danger text-danger w-100 delete show-delete-data-alert"
                        data-id="vendor-withdraw-offcanvas-delete-form-{{ $withdrawRequest['id'] }}"
                    >
                        <i class="fi fi-sr-trash d-flex"></i>
                        {{ translate('Delete') }}
                    </button>

                    <button type="button" class="btn btn--primary w-100" data-toggle="modal"
                            data-target="#editWithdrawInfoModal{{ $withdrawRequest['id'] }}">
                        <i class="fi fi-sr-pencil d-flex"></i>
                        {{ translate('Edit_Info') }}
                    </button>
                @elseif($withdrawRequest->approved == 1)
                    <button type="button" class="btn btn--primary w-100 disabled">
                        <i class="fi fi-sr-progress-complete d-flex"></i>
                        {{ translate('Already Approved') }}
                    </button>
                @elseif($withdrawRequest->approved == 2)
                    <button type="button" class="btn btn-danger w-100 disabled">
                        <i class="fi fi-sr-circle-xmark d-flex"></i>
                        {{ translate('Withdraw_Denied') }}
                    </button>
                @endif
            </div>

            @if ($withdrawRequest->approved == 0)
            <form id="vendor-withdraw-offcanvas-delete-form-{{ $withdrawRequest['id'] }}" method="GET" style="display: none;"
                  action="{{ route('vendor.business-settings.withdraw.close', [$withdrawRequest['id']]) }}">
                @csrf
                @method('GET')
            </form>
            @endif
        </div>

    </div>
</div>
