@php($currentCustomerData = $summaryData['currentCustomerData'] ?? null)
@php($cartNames = $summaryData['cartNames'] ?? [])
<?php
$totalCartItemProduct = 0;
foreach($cartItems['cartItemValue'] as $key => $item) {
    if(is_array($item)) {
        $totalCartItemProduct++;
    }
}
?>
@if ($summaryData['currentCustomer'] != 'Walk-In Customer')
    <div class="d-flex flex-column gap-2 mt-2 p-3 rounded bg-section">
        <div class="fw-medium fs-12">{{ translate('Customer_Information') }}</div>
        <div class="d-flex gap-2 align-items-center">
            <i class="fi fi-rr-user"></i>
            <span class="fw-semibold">{{ $currentCustomerData?->f_name.' '.$currentCustomerData?->l_name }}</span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <i class="fi fi-rr-phone-flip"></i>
            <a href="tel:{{ $currentCustomerData?->phone }}" class="text-dark">{{ $currentCustomerData?->phone }}</a>
        </div>
        @if($currentCustomerData->street_address && $currentCustomerData->city && $currentCustomerData->country)
            <div class="d-flex gap-2 align-items-center">
                <i class="fi fi-rr-marker"></i>
                <span class="fs-12">{{ $currentCustomerData->street_address . ','. $currentCustomerData->city.', '. $currentCustomerData->country }}</span>
            </div>
        @endif
    </div>
    @php( $walletStatus = getWebConfig('wallet_status') ?? 0)
    @if ($walletStatus)
        <input type="hidden" class="form-control customer-wallet-balance"
               value="{{usdToDefaultCurrency(amount: $currentCustomerData?->wallet_balance ?? 0)}}"
               readonly>
    @endif
@endif
