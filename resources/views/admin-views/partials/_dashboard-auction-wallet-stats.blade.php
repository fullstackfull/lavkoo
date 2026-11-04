@php
    $auctionWalletCards = [
        [
            'label'  => translate('Entry_Fee'),
            'amount' => $data['auction_total_entry_fee'] ?? 0,
            'image'  => 'public/assets/back-end/img/pa.png',
        ],
        [
            'label'  => translate('Tax'),
            'amount' => $data['auction_total_tax_collected'] ?? 0,
            'image'  => 'public/assets/back-end/img/ttc.png',
        ],
        [
            'label'  => translate('Commission Collected'),
            'amount' => $data['auction_total_commission_earned'] ?? 0,
            'image'  => 'public/assets/back-end/img/tcg.png',
        ],
        [
            'label'  => translate('Self_Auction_Shipping_Fee'),
            'amount' => $data['auction_total_shipping_fee'] ?? 0,
            'image'  => 'public/assets/back-end/img/shipping_method.png',
        ],
    ];
@endphp

<div class="col-xl-4">
    <div class="card border h-100 d-flex justify-content-center align-items-center">
        <div class="card-body d-flex flex-column gap-10 align-items-center justify-content-center">
            <img width="48" class="mb-2" src="{{ dynamicAsset(path: 'public/assets/back-end/img/auction-total-earning.png') }}" alt="">
            <h3 class="for-card-count mb-0 fz-24">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['auction_inhouse_earning'] ?? 0), currencyCode: getCurrencyCode()) }}</h3>
            <div class="text-capitalize mb-0 fs-14 text-dark fw-semibold">
                {{ translate('In-house_Total_Earning') }}
            </div>
        </div>
    </div>
</div>
<div class="col-xl-8">
    <div class="row g-2">
        @foreach($auctionWalletCards as $card)
            <div class="col-md-6">
                <div class="card border card-body h-100 justify-content-center">
                    <div class="d-flex gap-2 justify-content-between align-items-center">
                        <div class="d-flex flex-column align-items-start">
                            <h3 class="mb-1 fz-24">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $card['amount']), currencyCode: getCurrencyCode()) }}</h3>
                            <div class="text-capitalize mb-0 fs-14 text-dark">{{ $card['label'] }}</div>
                        </div>
                        <div>
                            <img width="40" class="mb-2" src="{{ dynamicAsset(path: $card['image']) }}" alt="{{ $card['label'] }}">
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
