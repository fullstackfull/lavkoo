<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ session('direction') ?? 'ltr' }}">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ 'Order Report Statement - '.$data['date_type'] }}</title>
    <meta http-equiv="Content-Type" content="text/html;"/>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/google-fonts.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/admin/order-transaction.css') }}">
</head>
<body>
<table class="content-position">
    <tr>
        <td>
            <table class="bs-0">
                <tr>
                    <th class="h3 p-0 text-left">
                        {{ translate('order_Report_Statement')}}
                    </th>
                    <th class="p-0 text-right">
                        @php($invoiceSettings = getWebConfig(name: 'invoice_settings'))
                        @if(isset($invoiceSettings['invoice_logo_status']) && $invoiceSettings['invoice_logo_status'] == 1)
                            @if(isset($invoiceSettings['invoice_logo_type']) && $invoiceSettings['invoice_logo_type'] == 'default')
                                <img height="40"
                                     src="{{ getStorageImages(path: getWebConfig(name: 'company_web_logo_png'), type:'backend-logo') }}"
                                     alt="" style="margin-bottom:5px;object-fit: contain">
                            @elseif(isset($invoiceSettings['invoice_logo_type']) && $invoiceSettings['invoice_logo_type'] == 'custom' && isset($invoiceSettings['image']))
                                <img height="40"
                                     src="{{ getStorageImages(path: imagePathProcessing(imageData:  $invoiceSettings['image'], path:'company'), type: 'backend-logo') }}"
                                     alt="" style="margin-bottom:5px;object-fit: contain">
                            @endif
                        @endif
                    </th>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td class="pt-0">
            <table class="bs-0">
                <tr>
                    <td class="p-0 text-left">
                        <b class="bold black">{{translate('date')}}</b> : {{ date('F d, Y') }} <span
                                class="block h-5"></span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="content-position">
    <tr>
        <td class="pt-0">
            <table class="bs-0">
                <tr>
                    <td class="p-0 text-left">
                        <table>
                            <tr>
                                <th class="bold black p-0 text-left p-3">{{translate('duration')}}</th>
                                <td class="p-0 p-3 text-capitalize">
                                    : {{ str_replace('_',' ', $data['date_type']) }}
                                </td>
                            </tr>
                            <tr>
                                <th class="bold black p-0 text-left p-3">{{translate('vendor_Info')}}</th>
                                <td class="p-0 p-3">:
                                    {{ ucfirst($data['seller']) }}
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td class="p-0 text-left">
                        <table>
                            <tr>
                                <th class="bold black p-0 text-left">{{ translate('total_Order') }} </th>
                                <td class="p-0p-3">:
                                    {{ $data['total_orders'] }}
                                </td>
                            </tr>

                            <tr>
                                <th class="bold black p-0 text-left">{{ translate('type') }}</th>
                                <td class="p-0p-3">:
                                    {{ ucfirst($data['type']) }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td></td>
    </tr>
    <tr>
        <td class="pt-0">
            <table class="bs-0 __product-table inter">
                <tbody>
                <tr>
                    <td class="pl-0 pr-0" style="background: #0177CD !important;color: white;font-weight: bold;">{{translate('SL')}}</td>
                    <td style="background: #0177CD !important;color: white;font-weight: bold;">{{translate('details')}}</td>
                    <td class="text-right" style="background: #0177CD !important;color: white;font-weight: bold;">{{translate('amount')}}</td>
                </tr>
                <tr>
                    <td class="text-center">1</td>
                    <td>{{translate('total_Ordered_Amount')}}</td>
                    <td class="text-right">
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_order_amount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">2</td>
                    <td>{{translate('total_Ordered_Return_Amount')}}</td>
                    <td class="text-right">
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_order_return_amount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">3</td>
                    <td>{{translate('total_Ordered_Due_Amount')}}</td>
                    <td class="text-right">
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['tota_order_due_amount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">4</td>
                    <td>{{translate('total_Product_Discount')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_product_discount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">5</td>
                    <td>{{translate('total_Coupon_Discount')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_coupon_discount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">6</td>
                    <td>{{ translate('total_Referral_Discount') }}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_referral_discount']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">7</td>
                    <td>{{translate('total_Shipping_Charge')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_delivery_charge']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">8</td>
                    <td>{{translate('total')}} {{translate('VAT/TAX')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_tax']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">9</td>
                    <td>{{translate('total_Order_Commission')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_order_commission']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                <tr>
                    <td class="text-center">10</td>
                    <td>{{translate('total_Deliveryman_Incentive')}}</td>
                    <td class="text-right">
                        {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_deliveryman_incentive']), currencyCode: getCurrencyCode()) }}
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
</table>
<br>
<table class="">
    <tr>
        <th class="content-position-y bg-light py-4 footer">
            <div class="d-flex justify-content-center gap-2">
                <div class="mb-2">
                    <i class="fa fa-phone"></i>
                    {{translate('phone')}}
                    : {{ $data['company_phone'] }}
                </div>
                <div class="mb-2">
                    <i class="fa fa-envelope" aria-hidden="true"></i>
                    {{translate('email')}}
                    : {{ $data['company_email'] }}
                </div>
            </div>
            <div class="mb-2">
                {{url('/')}}
            </div>
            <div>
                {{translate('all_copy_right_reserved_©_'.date('Y').'_'). $data['company_name'] }}
            </div>
        </th>
    </tr>
</table>
</body>
</html>
