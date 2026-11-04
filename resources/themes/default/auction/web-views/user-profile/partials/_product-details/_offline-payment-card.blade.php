@if(!$isOwner && $isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->auction_current_status == 'live' && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid')
    <?php
        $participation      = $auctionProduct->myParticipation;
        $transaction        = $participation->auctionTransaction;
        $rawPaymentInfo     = $transaction?->payment_info;
        $paymentInfo        = $rawPaymentInfo
            ? (is_array($rawPaymentInfo) ? $rawPaymentInfo : json_decode($rawPaymentInfo, true))
            : [];
        $methodId           = $paymentInfo['method_id'] ?? null;
        $offlineMethod      = $methodId ? $offline_payment_methods->firstWhere('id', $methodId) : null;
        $methodFields = $offlineMethod
            ? (is_array($offlineMethod->method_fields)
                ? $offlineMethod->method_fields
                : json_decode($offlineMethod->method_fields, true))
            : [];
        $paymentStatus = $participation->entry_fee_payment_status ?? 'pending';
    ?>

    <div class="card bs-border shadow-sm">
        <div class="card-body p-15px d-flex flex-column gap-3">

            <div class="d-flex align-items-center justify-content-between gap-2">
                <h6 class="fs-14 fw-semibold title-clr mb-0">
                    {{ translate('Participate Payment Details') }}
                </h6>
                @if($paymentStatus === 'pending')
                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-10px py-1 fs-12 fw-medium">
                        {{ translate('Pending') }}
                    </span>
                @elseif($paymentStatus === 'verified')
                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-10px py-1 fs-12 fw-medium">
                        {{ translate('Verified') }}
                    </span>
                @elseif($paymentStatus === 'denied')
                    <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-10px py-1 fs-12 fw-medium">
                        {{ translate('Denied') }}
                    </span>
                @endif
            </div>

            <div class="d-flex align-items-center justify-content-between gap-2">
                <span class="fs-13 title-semidark">{{ translate('Auction Payment Participate fee') }}</span>
                <strong class="fs-14 fw-semibold title-clr">
                    {{ webCurrencyConverter(amount: $entryFeeAmount) }}
                </strong>
            </div>

            <div class="offline-payment-details-wrap d-flex flex-column gap-3">
                @if($paymentStatus === 'denied' && !empty($participation?->entry_fee_denied_note))
                    <div class="bg-danger bg-opacity-10 rounded p-3">
                        <h6 class="fs-13 fw-semibold title-clr mb-3">
                            {{ translate('Denied Note') }}
                        </h6>
                        <div class="d-flex flex-column gap-2">
                            <p>{{ $participation?->entry_fee_denied_note }}</p>
                        </div>
                    </div>
                @endif

                @if($paymentStatus === 'denied')
                    <div class="bg-info bg-opacity-10 rounded p-3 d-flex align-items-center gap-2">
                        <i class="fi fi-sr-info text-info"></i>
                        <span class="fs-13 title-clr">
                            {{ translate('If_you_have_any_further_queries_please') }}
                            <a href="{{ route('account-tickets') }}" class="text-info fw-semibold text-underline">{{ translate('create_a_ticket') }}</a>
                        </span>
                    </div>
                @endif

                @if($offlineMethod && !empty($methodFields))
                    <div class="light-box rounded p-3">
                        <h6 class="fs-13 fw-semibold title-clr mb-3">
                            {{ $offlineMethod['method_name'] }} {{ translate('Details') }}
                        </h6>
                        <div class="d-flex flex-column gap-2">
                            @foreach($methodFields as $info)
                                <div class="d-flex align-items-start gap-2">
                                    <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                        {{ translate(ucwords(str_replace('_', ' ', $info['input_name'] ?? ''))) }}
                                    </span>
                                    <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                    <span class="fs-13 title-clr fw-medium">{{ $info['input_data'] ?? '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if(!empty($paymentInfo))
                    <div class="light-box rounded p-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                            <h6 class="fs-13 fw-semibold title-clr mb-0">{{ translate('My Submitted info') }}</h6>
                            @if($paymentStatus === 'pending')
                                <button type="button"
                                        class="btn btn-outline-primary p-0 d-flex align-items-center justify-content-center rounded js-edit-offline-payment-btn"
                                        style="width:28px;height:28px;min-width:28px;"
                                        data-method-id="{{ $methodId }}"
                                        data-amount="{{ $entryFeeAmount }}"
                                        data-payment-info='{!! json_encode($paymentInfo, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) !!}'>
                                    <i class="fi fi-rr-pencil fs-12"></i>
                                </button>
                            @endif
                        </div>
                        <div class="d-flex flex-column gap-2">
                            @foreach($paymentInfo as $key => $value)
                                @if(in_array($key, ['method_id', 'payment_note', 'method_informations']) || is_array($value)) @continue @endif
                                <div class="d-flex align-items-start gap-2">
                                    <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                        {{ translate(ucwords(str_replace('_', ' ', $key))) }}
                                    </span>
                                    <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                    <span class="fs-13 title-clr fw-medium">{{ filled($value) ? $value : translate('N/A') }}</span>
                                </div>
                            @endforeach
                            @foreach($paymentInfo['method_informations'] ?? [] as $fieldKey => $fieldValue)
                                <div class="d-flex align-items-start gap-2">
                                    <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                        {{ translate(ucwords(str_replace('_', ' ', $fieldKey))) }}
                                    </span>
                                    <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                    <span class="fs-13 title-clr fw-medium">{{ filled($fieldValue) ? $fieldValue : translate('N/A') }}</span>
                                </div>
                            @endforeach
                        </div>
                        @if(!empty($paymentInfo['payment_note']))
                            <div class="mt-2 fs-13 title-semidark">
                                {{ translate('Payment Note') }} :
                                <span class="title-clr fw-medium">{{ $paymentInfo['payment_note'] }}</span>
                            </div>
                        @endif
                    </div>
                @endif

            </div>
            <div class="text-center">
                <button type="button"
                        class="btn btn-link text-primary fs-13 p-0 js-offline-payment-toggle"
                        data-more="{{ translate('See More') }}"
                        data-less="{{ translate('See Less') }}">
                    {{ translate('See Less') }}
                </button>
            </div>

        </div>
    </div>
@endif
