@php
    $auctionWalletCards = [
        [
            'label' => translate('Total_Earning'),
            'amount' => $dashboardData['auction_total_earning'] ?? 0,
            'image' => 'public/assets/back-end/img/auction-total-earning.png',
        ],
        [
            'label' => translate('Vat_Collected'),
            'amount' => $dashboardData['auction_total_vat'] ?? 0,
            'image' => 'public/assets/back-end/img/aw.png',
        ],
        [
            'label' => translate('Total_Shipping'),
            'amount' => $dashboardData['auction_total_shipping'] ?? 0,
            'image' => 'public/assets/back-end/img/shipping_method.png',
        ],
        [
            'label' => translate('Withdrawable_Balance'),
            'amount' => $dashboardData['auction_withdrawable_balance'] ?? 0,
            'image' => 'public/assets/back-end/img/balance.png',
        ],
        [
            'label' => translate('Pending_Withdraw'),
            'amount' => $dashboardData['auction_pending_withdraw'] ?? 0,
            'image' => 'public/assets/back-end/img/withdraw.png',
        ],
        [
            'label' => translate('Commission_Given'),
            'amount' => $dashboardData['auction_total_commission_given'] ?? 0,
            'image' => 'public/assets/back-end/img/tcg.png',
        ],
        [
            'label' => translate('Pending_Commission'),
            'amount' => $dashboardData['auction_pending_commission'] ?? 0,
            'image' => 'public/assets/back-end/img/pending.png',
        ],
        [
            'label' => translate('Order_Pending'),
            'amount' => $dashboardData['auction_total_pending_amount'] ?? 0,
            'image' => 'public/assets/back-end/img/pw.png',
        ],
    ];
@endphp

@foreach($auctionWalletCards as $card)
    <div class="{{ $loop->iteration <= 2 ? 'col-md-6' : 'col-md-6 col-lg-4' }}">
        <div class="card card-body h-100 justify-content-center">
            <div class="d-flex gap-2 justify-content-between align-items-center">
                <div class="d-flex flex-column align-items-start">
                    <h3 class="mb-1 fz-24">
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $card['amount']), currencyCode: getCurrencyCode(type: 'default')) }}
                    </h3>
                    <div class="text-capitalize mb-0">{{ $card['label'] }}</div>
                </div>
                <div>
                    <img width="40" src="{{ dynamicAsset(path: $card['image']) }}" alt="{{ $card['label'] }}">
                </div>
            </div>
        </div>
    </div>
@endforeach
