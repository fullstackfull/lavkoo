<div class="offcanvas offcanvas-end" tabindex="-1" id="withdrawInfoViewOffcanvas{{ $withdrawRequest['id'] }}"
         aria-labelledby="withdrawInfoViewOffcanvas{{ $withdrawRequest['id'] }}Label" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Withdraw_Information') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="d-flex justify-content-between gap-3 mb-4">
                <div class="flex-grow-1 fs-16 fs-12-mobile">
                    <div class="d-flex gap-2 mb-2">
                        <span>{{ translate('Withdraw_Amount') }} :</span>
                        <span class="fw-medium text-dark">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawRequest['amount']), currencyCode: getCurrencyCode(type: 'default')) }}
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <span>{{ translate('Request_Time') }} : </span>
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
                        :
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
    </div>
