{{--
    What this order is worth to the seller.

    The same two services the seller app reads, so the panel and the app cannot
    disagree about a margin. Where the marketplace has not booked the earning yet
    this says so and shows no figure — a seller reads "you receive" as what will
    land in their account, so it may only come from the record that will pay them.
--}}
@if($sellerEarning)
    <div class="card card-body">
        <h4 class="fw-bold mb-3">{{ translate('Your_earning') }}</h4>

        <div class="d-grid gap-2 fs-12">
            <div class="d-flex justify-content-between">
                <span>{{ translate('Items_total') }}</span>
                <span class="fw-semibold">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $sellerEarning['items']['price']), currencyCode: getCurrencyCode()) }}</span>
            </div>

            @if($sellerEarning['items']['discount'] > 0)
                <div class="d-flex justify-content-between">
                    <span>{{ translate('Discount') }}</span>
                    <span class="fw-semibold">- {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $sellerEarning['items']['discount']), currencyCode: getCurrencyCode()) }}</span>
                </div>
            @endif

            @if($sellerEarning['items']['tax'] > 0)
                <div class="d-flex justify-content-between">
                    <span>{{ translate('Tax') }}</span>
                    <span class="fw-semibold">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $sellerEarning['items']['tax']), currencyCode: getCurrencyCode()) }}</span>
                </div>
            @endif

            @if(!is_null($sellerEarning['commission_amount']))
                <div class="d-flex justify-content-between text-danger">
                    <span>{{ translate('Marketplace_commission') }}</span>
                    <span class="fw-semibold">- {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $sellerEarning['commission_amount']), currencyCode: getCurrencyCode()) }}</span>
                </div>

                {{-- The rule that charged, not just the amount it took. --}}
                @foreach($sellerEarning['commission_rules'] as $rule)
                    @if($rule['label'])
                        <div class="text-muted ps-2">
                            {{ $rule['label'] }}@if(!is_null($rule['percentage'])) · {{ rtrim(rtrim(number_format($rule['percentage'], 2), '0'), '.') }}%@endif
                        </div>
                    @endif
                @endforeach
            @endif

            <hr class="my-1">

            @if(!is_null($sellerEarning['seller_receives']))
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold">{{ translate('You_receive') }}</span>
                    <span class="fw-bold fs-16 text--primary">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $sellerEarning['seller_receives']), currencyCode: getCurrencyCode()) }}</span>
                </div>
            @else
                <div class="text-muted">{{ translate('The_marketplace_has_not_recorded_the_earning_for_this_order_yet') }}</div>
            @endif

            @foreach($sellerEarning['ledger'] as $entry)
                <div class="d-flex justify-content-between align-items-center">
                    <span>{{ translate($entry['entry_type']) }}</span>
                    <span class="d-flex gap-2 align-items-center">
                        @if($entry['status'] === 'pending' && $entry['available_at'])
                            {{-- When a pending earning becomes withdrawable: the most asked
                                 question about a seller's balance. --}}
                            <span class="text-muted">{{ translate('Available_on') }} {{ date('d M Y', strtotime($entry['available_at'])) }}</span>
                        @endif
                        <span class="fw-semibold {{ $entry['credit'] > 0 ? 'text-success' : 'text-danger' }}">
                            {{ $entry['credit'] > 0 ? '' : '- ' }}{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $entry['credit'] > 0 ? $entry['credit'] : $entry['debit']), currencyCode: getCurrencyCode()) }}
                        </span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif

@if(!empty($sellerTimeline['events']))
    <div class="card card-body">
        <h4 class="fw-bold mb-3">{{ translate('Order_timeline') }}</h4>

        @if($sellerTimeline['sla'])
            {{-- The marketplace's own deadline, not a second and kinder clock. --}}
            <div class="alert {{ $sellerTimeline['sla']['is_late'] ? 'alert-danger' : 'alert-info' }} py-2 px-3 fs-12">
                @if($sellerTimeline['sla']['is_late'])
                    {{ translate('This_order_is_late_by') }} {{ abs(round($sellerTimeline['sla']['hours_left'], 1)) }} {{ translate('hours') }}
                @else
                    {{ round($sellerTimeline['sla']['hours_left'], 1) }} {{ translate('hours_left_to_process_this_order') }}
                @endif
            </div>
        @endif

        <div class="d-grid gap-2 fs-12">
            @foreach($sellerTimeline['events'] as $event)
                <div class="d-flex gap-2">
                    <span class="text-muted flex-shrink-0" style="width:130px">{{ date('d M Y H:i', strtotime($event['at'])) }}</span>
                    <span>
                        <span class="fw-semibold">{{ translate($event['key']) }}</span>
                        @if($event['actor'] || $event['note'])
                            <span class="text-muted d-block">{{ collect([$event['actor'] ? translate($event['actor']) : null, $event['note']])->filter()->implode(' · ') }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif
