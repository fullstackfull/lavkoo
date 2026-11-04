<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/pos-invoice.css') }}">
<?php
$orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order);
?>
<div class="width-363px border px-3 py-2">
    <div class="text-center pt-3 overflow-wrap-anywhere">
        <div class="d-inline-block mb-10px">
            <img width="24" height="24" class="pos-logo"
                 src="{{ getStorageImages(path: $shop?->image_full_url ?? null, type: 'backend-logo') ?: getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}"
                 alt="{{ translate('shop_logo') }}">
            <h2 class="font-size-16px line-height-1 text-body px-1 mb-0 d-inline-block">{{ $shop->name ?? getWebConfig(name: 'company_name') }}</h2>
        </div>
        <p class="fs-12 mb-0">
            {{ $shop->address ?? getWebConfig('shop_address') }}
        </p>
    </div>
    <span class="dashed-hr"></span>

    <table class="w-100 text-body fs-12 overflow-wrap-anywhere">
        <tbody>
            <tr>
                <td class="text-center text-dark">{{ translate('order_ID') }} : {{ $order['id'] ?? '' }}</td>
            </tr>
            <tr>
                <td class="text-center text-dark">
                    {{ $order['created_at'] ? date('d/M/Y h:i a', strtotime($order['created_at'])) : '' }}
                </td>
            </tr>
        </tbody>
    </table>
    <span class="dashed-hr"></span>

    @if($order->customer)
        <table class="w-100 text-body fs-12 overflow-wrap-anywhere">
            <tbody>
                <tr>
                    <td>{{ translate('customer_name') }}</td>
                    <td colspan="2"></td>
                    <td class="text-end text-dark">{{ ($order->customer['f_name'] ?? '') . ' ' . ($order->customer['l_name'] ?? '') }}</td>
                </tr>
                @if ($order->customer->id != 0)
                <tr>
                    <td>{{ translate('phone') }}</td>
                    <td colspan="2"></td>
                    <td class="text-end text-dark">{{ $order->customer['phone'] ?? '' }}</td>
                </tr>
                @endif
            </tbody>
        </table>
        <span class="dashed-hr"></span>
    @endif

    <table class="w-100 text-body fs-12 overflow-wrap-anywhere text-nowrap td-p-1">
        <tbody>
        @php($sub_total=0)
        @php($total_dis_on_pro=0)
        @php($product_price=0)
        @php($total_product_price=0)
        @php($ext_discount=0)
        @php($coupon_discount=0)
        @foreach($order->details ?? [] as $detail)
            @if($detail->product)
                <tr>
                    <td>{{ $loop->iteration }}.</td>
                    <td colspan="2"></td>
                    <td class="text-wrap">
                        <span> {{ Str::limit($detail->product['name'] ?? '', 200) }}</span><br>
                        @php($detailVariationArray = json_decode($detail['variation'] ?? '[]', true) ?? [])
                        @php($detailVariationCount=0)
                        @foreach($detailVariationArray as $key1 => $variation)
                            @if(!empty($key1) && !empty($variation))
                                @php($detailVariationCount++)
                            @endif
                        @endforeach
                        @if(($detail->product->product_type ?? '') == 'physical' && $detailVariationCount > 0)
                            <strong><u>{{ translate('variation') }} : </u></strong>
                            @foreach($detailVariationArray as $key1 => $variation)
                                @if(!empty($key1) && !empty($variation))
                                    <div class="font-size-sm text-body text-dark">
                                        <span>{{ translate($key1) }}:</span>
                                        <span class="font-weight-bold">{{ $variation }}</span>
                                    </div>
                                @endif
                            @endforeach
                        @endif

                        @if(isset($detail['discount']) && $detail['discount'] > 0)
                            {{ translate('discount') }} :
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['discount']), currencyCode: getCurrencyCode()) }}
                        @endif
                    </td>
                    <td colspan="2"></td>
                    <td class="text-dark">
                        x{{ $detail['qty'] ?? 0 }}
                    </td>
                    <td colspan="2"></td>
                    <td class="text-end text-dark">
                        @php($amount = (($detail['price'] ?? 0) * ($detail['qty'] ?? 0)) - ($detail['discount'] ?? 0))
                        @php($product_price = ($detail['price'] ?? 0) * ($detail['qty'] ?? 0))
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $amount), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                @php($sub_total+=$amount)
                @php($total_product_price+=$product_price)
            @endif
        @endforeach
        </tbody>
    </table>
    <span class="dashed-hr"></span>

    <table class="w-100 text-body fs-12 overflow-wrap-anywhere">
        <tr>
            <td>{{ translate('items_Price') }}:</td>
            <td colspan="2"></td>
            <td class="text-end text-dark">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemPrice'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
        </tr>
        <tr>
            <td>{{ translate('item_discount') }}:</td>
            <td colspan="2"></td>
            <td class="text-end text-dark">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
        </tr>
        <tr>
            <td>{{ translate('extra_discount') }}:</td>
            <td colspan="2"></td>
            <td class="text-end text-dark">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['extraDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
        </tr>
        <tr>
            <td>{{ translate('subtotal') }}:</td>
            <td colspan="2"></td>
            <td class="text-end text-dark">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['subTotal'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
        </tr>

        @php($systemTaxConfig = getTaxModuleSystemTypesConfig())
        @if(($systemTaxConfig['SystemTaxVat']['is_active'] ?? false) && !($systemTaxConfig['is_included'] ?? false))
            <tr>
                <td>{{ translate('tax') }} / {{ translate('VAT') }}:</td>
                <td colspan="2"></td>
                <td class="text-end text-dark">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['taxTotal'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
            </tr>
        @endif

        <tr>
            <td>{{ translate('coupon_discount') }}:</td>
            <td colspan="2"></td>
            <td class="text-end text-dark">-{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['couponDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</td>
        </tr>
        @if(($orderTotalPriceSummary['referAndEarnDiscount'] ?? 0) > 0)
            <tr>
                <td>{{ translate('referral_discount') }}:</td>
                <td colspan="2"></td>
                <td class="text-end text-dark">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['referAndEarnDiscount']), currencyCode: getCurrencyCode()) }}</td>
            </tr>
        @endif
        <tr>
            <td class="text-dark">
                {{ translate('total') }}
                <span class="fs-10 fw-medium">{{ ($orderTotalPriceSummary['tax_model'] ?? '') == 'include' ? '('.translate('Tax_:_Inc.').')' : '' }}</span>
                :
            </td>
            <td colspan="2"></td>
            <td class="text-end text-dark">
                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount'] ?? 0), currencyCode: getCurrencyCode()) }}
            </td>
        </tr>
        @if (in_array(strtolower($order->order_type ?? ''), ['pos']))
            <tr>
                <td colspan="4">
                    <span class="dashed-hr"></span>
                </td>
            </tr>
            <tr>
                <td>
                    {{ translate('paid_by') }}:
                </td>
                <td colspan="2"></td>
                <td class="text-end">
                    {{ translate($order->payment_method ?? '') }}
                </td>
            </tr>

            <tr>
                <td>
                    {{ translate('Paid_Amount') }}:
                </td>
                <td colspan="2"></td>
                <td class="text-end">
                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['paidAmount'] ?? 0), currencyCode: getCurrencyCode()) }}
                </td>
            </tr>

            <tr>
                <td>
                    {{ translate('Change_Amount') }}:
                </td>
                <td colspan="2"></td>
                <td class="text-end">
                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['changeAmount'] ?? 0), currencyCode: getCurrencyCode()) }}
                </td>
            </tr>
        @endif
    </table>
    <span class="dashed-hr"></span>

    <p class="text-center mb-0 text-dark fs-12 overflow-wrap-anywhere">
        {{ translate('thank_you_for_buying._please_visit') }} {{ $shop->name ?? getWebConfig(name: 'company_name') }} <span class="text-lowercase">{{ translate('again') }}</span>.
    </p>
    <span class="dashed-hr"></span>

    <p class="text-center mb-0 text-dark fs-12 overflow-wrap-anywhere">
        {{ translate('Powered_by') }} {{ $shop->name ?? getWebConfig(name: 'company_name') }}, {{ translate('phone') }} : {{ getWebConfig('company_phone') }}
    </p>
</div>
