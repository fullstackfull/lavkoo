@php
    use Illuminate\Support\Facades\Session;
    $currencyCode = getCurrencyCode(type: 'web');
    $direction = Session::get('direction');
    $lang = getDefaultLanguage();
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{$direction}}"
      style="text-align: {{$direction === "rtl" ? 'right' : 'left'}};"
      xmlns="http://www.w3.org/1999/html">
<head>
    <meta charset="UTF-8">
    <title>{{ translate('invoice')}}</title>
    <meta http-equiv="Content-Type" content="text/html;"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2JL7SUc.woff2')}}) format('woff2');
            unicode-range: U+0460-052F, U+1C80-1C88, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F;
        }

        /* cyrillic */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa0ZL7SUc.woff')}}) format('woff2');
            unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
        }

        /* greek-ext */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2ZL7SUc.woff')}}) format('woff2');
            unicode-range: U+1F00-1FFF;
        }

        /* greek */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1pL7SUc.woff')}}) format('woff2');
            unicode-range: U+0370-0377, U+037A-037F, U+0384-038A, U+038C, U+038E-03A1, U+03A3-03FF;
        }

        /* vietnamese */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2pL7SUc.woff')}}) format('woff2');
            unicode-range: U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB;
        }

        /* latin-ext */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa25L7SUc.woff')}}) format('woff2');
            unicode-range: U+0100-02AF, U+0304, U+0308, U+0329, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }

        /* latin */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url({{dynamicAsset('public/assets/front-end/fonts/Inter/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff')}}) format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }

        * {
            margin: 0;
            padding: 0;
            line-height: 1.6;
            font-family: "Inter", sans-serif;
            color: #7F8185;
        }

        .ltr {
            direction: ltr;
        }

        .rtl {
            direction: rtl;
        }

        body {
            font-size: 9px !important;
            font-family: "Inter", sans-serif;
            font-optical-sizing: auto;
            font-weight: < style weight >;
            font-style: normal;
            font-variation-settings: "slnt" 0;
            color: #7F8185;
        }

        .main-content {
            padding: 0 20px 20px;
            width: 595px;
            margin: 0 auto;

        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: #FAFAFA;
            text-align: center;
            padding: 10px;
        }

        .invoice-end-note {
            position: fixed;
            bottom: 0;
        }

        img {
            max-width: 100%;
        }

        .logo {
            margin-bottom: 5px;
            object-fit: contain;
            width: 80px;
            height: auto;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: {{$direction === "rtl" ? 'right' : 'left'}}        !important;
        }

        .text-right {
            text-align: {{$direction === "rtl" ? 'left' : 'right'}} !important;
        }
        table th.text-right {
            text-align: {{$direction === "rtl" ? 'left' : 'right'}} !important;
        }

        .ml-auto {
            margin- {{ $direction === "rtl" ? 'left' : 'right' }}: auto !important;
        }

        .text-dark, h1, h2, h3, h4, h5, h6, .table thead th {
            color: #303030 !important;
        }

        .text-body {
            color: #7F8185 !important;
        }

        h1 {
            font-weight: 700 !important;
        }

        h2, h3, h4, h5, h6 {
            font-weight: 600 !important;
        }

        strong, .fw-bold {
            font-weight: {{$lang == 'bd' ?'700':'bold' }};
        }

        .table thead th, .fw-semibold {
            font-weight: 600 !important;
        }

        .fw-medium {
            font-weight: 500 !important;
        }

        .fw-normal {
            font-weight: 400 !important;
        }

        .fs-20 {
            font-size: 20px !important;
        }

        h4, .fs-11 {
            font-size: 11px !important;
        }

        h5, .table thead th, .fs-10 {
            font-size: 10px !important;
        }

        .fs-9 {
            font-size: 9px !important;
        }

        h6, p, td {
            font-size: 9px !important;
        }

        .text-nowrap {
            white-space: nowrap;
        }

        .border-dashed-top {
            border-top: 1px dashed #D7DAE0;
            display: block;
            width: 100%;
            margin-left: 8px;
            margin-right: 8px;
        }

        .border {
            border: 1px solid #D7DAE0;
        }

        .border-bottom {
            border-bottom: 1px solid #D7DAE0;
        }

        .border-left {
            border-left: 1px solid #D7DAE0;
        }

        .border-right {
            border-right: 1px solid #D7DAE0;
        }

        .rounded {
            border-radius: 5px !important;
        }

        .m-0 {
            margin: 0;
        }

        .m-1 {
            margin: 4px;
        }

        .m-2 {
            margin: 8px;
        }

        .m-3 {
            margin: 16px;
        }

        .m-4 {
            margin: 24px;
        }

        .mt-0 {
            margin-top: 0;
        }

        .mt-1 {
            margin-top: 4px;
        }

        .mt-2 {
            margin-top: 8px;
        }

        .mt-3 {
            margin-top: 16px;
        }

        .mt-4 {
            margin-top: 24px;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .mb-1 {
            margin-bottom: 4px;
        }

        .mb-2 {
            margin-bottom: 8px;
        }

        .mb-3 {
            margin-bottom: 16px;
        }

        .mb-4 {
            margin-bottom: 24px;
        }

        .mb-30 {
            margin-bottom: 30px;
        }

        .p-0 {
            padding: 0;
        }

        .p-1 {
            padding: 4px;
        }

        .p-2 {
            padding: 8px;
        }

        .p-3 {
            padding: 16px;
        }

        .p-4 {
            padding: 24px;
        }

        .pt-0 {
            padding-top: 0;
        }

        .pt-1 {
            padding-top: 4px;
        }

        .pt-2 {
            padding-top: 8px;
        }

        .pt-3 {
            padding-top: 16px;
        }

        .pt-4 {
            padding-top: 24px;
        }

        .pb-0 {
            padding-bottom: 0;
        }

        .pb-1 {
            padding-bottom: 4px;
        }

        .pb-2 {
            padding-bottom: 8px;
        }

        .pb-3 {
            padding-bottom: 16px;
        }

        .pb-4 {
            padding-bottom: 24px;
        }

        .px-0 {
            padding-left: 0;
            padding-right: 0;
        }

        .px-1 {
            padding-left: 4px;
            padding-right: 4px;
        }

        .px-2 {
            padding-left: 8px;
            padding-right: 8px;
        }

        .px-3 {
            padding-left: 16px;
            padding-right: 16px;
        }

        .w-100 {
            width: 100% !important;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .table thead th {
            background-color: #F9FAFC;
        }

        .vertical-align-top {
            vertical-align: top
        }

        .text-success {
            color: #04BB7B !important;
        }

        .text-danger {
            color: #FF4040 !important;
        }

        .note {
            background-color: rgba(60, 118, 241, 0.05);
            color: #303030;
            padding: 5px;
            border-radius: 5px;
        }

        td, th {
            white-space: normal;
            word-break: break-word;
        }


        @media print {
            body {
                font-size: 9px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }

            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            tbody {
                display: table-row-group;
            }

            td,
            th {
                white-space: normal;
                word-wrap: break-word;
                font-size: 9px !important;
                line-height: 1.4 !important;
            }

            table th {
                font-size: 10px !important;
            }

            table td {
                font-size: 9px !important;
            }

            .invoice-end-note {
                page-break-inside: avoid;
                break-inside: avoid;
            }

        }
    </style>
</head>
<body>

<?php
$orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order);
?>
<div class="main-content">
    <table class="table">
        <tbody>
        <tr>
            <td class="text-left p-2">
                <h1 class="fs-20 mb-2">{{ translate('INVOICE') }}</h1>
            </td>
            <td class="text-right p-2">
                @if(isset($invoiceSettings['invoice_logo_status']) && $invoiceSettings['invoice_logo_status'] == 1)
                    @if(isset($invoiceSettings['invoice_logo_type']) && $invoiceSettings['invoice_logo_type'] == 'default')
                        <img height="30"
                             src="{{ getStorageImages(path: getWebConfig(name: 'company_web_logo_png'), type:'backend-logo') }}"
                             alt="" class="logo">
                    @elseif(isset($invoiceSettings['invoice_logo_type']) && $invoiceSettings['invoice_logo_type'] == 'custom' && isset($invoiceSettings['image']))
                        <img height="30"
                             src="{{ getStorageImages(path: imagePathProcessing(imageData:  $invoiceSettings['image'], path:'company'), type: 'backend-logo') }}"
                             alt="" class="logo">
                    @endif
                @endif
            </td>
        </tr>
        <tr>
            <td class="text-left px-2">
                <table>
                    <tbody>
                    <tr>
                        <td>
                            <p>{{ translate('Invoice_Date') }} : <span
                                    class="text-dark fw-semibold"> {{date('M d ,Y',strtotime($order['created_at']))}}</span>
                            </p>
                        </td>
                        @if($order['seller_is']!='admin' && isset($order['seller']) && $order['seller']->gst != null)
                            <td>
                                <p>{{ translate('GST') }} : <span
                                        class="text-dark fw-semibold">{{ $order['seller']->gst }}</span></p>
                            </td>
                        @endif
                    </tr>
                    </tbody>
                </table>
            </td>
            <td class="text-right px-2">
                <p class="text-dark p-0">{{getWebConfig('shop_address') }}</p>
                @if(isset($invoiceSettings['business_identity_status']) && $invoiceSettings['business_identity_status'])
                    <p class="text-dark p-0">
                        <span class="fw-semibold">{{ $invoiceSettings['business_identity'] }}</span> : {{ $invoiceSettings['business_identity_value'] }}
                    </p>
                @endif
            </td>
        </tr>
        </tbody>
    </table>
    <table class="table mb-3">
        <tbody>
        <tr>
            <td colspan="3" class="p-2"></td>
        </tr>
        <tr>
            <td colspan="3" class="border-dashed-top px-2"></td>
        </tr>
        <tr>
            <td colspan="3" class="p-1"></td>
        </tr>
        <tr>
            <td class="text-left" colspan="2">
                <table>
                    <tbody>
                    <tr>
                        <td class="px-2 pt-1 pb-1">{{ translate('Order_Id') }}</td>
                        <td class="px-2 pt-1 pb-1">: <span class="text-dark">#{{ $order->id }}</span></td>
                    </tr>
                    <tr>
                        <td class="px-2 pt-1 pb-1">{{ translate('Status') }}</td>
                        <td class="px-2 pt-1 pb-1">:
                            <span class="text-dark">
                            {{ ucfirst(str_replace('_',' ', $order->order_status)) }}
                        </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="px-2 pt-1 pb-1">{{ translate('Date') }}</td>
                        <td class="px-2 pt-1 pb-1">: <span
                                class="text-dark">{{date('M d, Y',strtotime($order['created_at']))}}</span></td>
                    </tr>
                    </tbody>
                </table>
            </td>
            <td class="text-right">
                <table class="table text-right">
                    <tbody>
                    <tr>
                        <td class="px-2 pt-1 pb-1 text-right">
                            <p>{{ translate('Invoice_of') }} {{' ( '.$currencyCode.' )'}}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="px-2 pt-1 pb-1 text-right">
                            <h5 class="fs-11">
                                {{ webCurrencyConverter(amount: $orderTotalPriceSummary['totalAmount']) }}
                            </h5>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="p-1"></td>
        </tr>
        <tr>
            <td colspan="3" class="border-dashed-top p-2"></td>
        </tr>

        <tr>
            <td colspan="3">
                <table class="table">
                    <tbody>
                    <tr>
                        <td class="vertical-align-top">
                            <table>
                                <tbody>
                                <tr>
                                    <td class="px-2" colspan="3">
                                        <h5>{{ translate('Payment_info') }}</h5>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-2 pt-1 pb-1">{{ translate('Method') }}</td>
                                    <td class="pt-1 pb-1">:</td>
                                    <td class="px-2 pt-1 pb-1"><span
                                            class="text-dark">{{ str_replace('_',' ',$order->payment_method) }}</span>
                                    </td>
                                </tr>
                                @if(!empty($order->transaction_ref))
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('reference_ID') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $order->transaction_ref }}</span>
                                        </td>
                                    </tr>
                                @endif
                                @if($order->offlinePayments)
                                    @foreach ($order->offlinePayments?->payment_info as $key=>$item)
                                        @if (isset($item) && $key != 'method_id')
                                            <tr>
                                                <td class="px-2 pt-1 pb-1">{{ str_replace('_',' ',$key) }}</td>
                                                <td class="pt-1 pb-1">:</td>
                                                <td class="px-2 pt-1 pb-1"><span
                                                        class="text-dark">{{ $item }}</span>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                @endif

                                @if($order['edited_status'] == 1 && ($order?->latestEditHistory?->order_due_payment_status == "paid" || $order?->latestEditHistory?->order_due_payment_status == "cash_on_delivery"))
                                    <tr>
                                        <td colspan="3" class="px-2 pt-1 pb-1">
                                            <strong> {{ translate('Another_Payment_Info') }} </strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Status') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-success">{{ translate($order?->latestEditHistory?->order_due_payment_status) }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Method') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{str_replace('_',' ',$order?->latestEditHistory?->order_due_payment_method)}}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Due_amount') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_due_amount) }}</span>
                                        </td>
                                    </tr>
                                @elseif($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == "returned")
                                    <tr>
                                        <td colspan="3" class="px-2 pt-1 pb-1">
                                            <strong>{{ translate('Amount_to_be_returned') }} </strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('After_editing_your_product_list_you_will_return_') }} {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_return_amount)  }}</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </td>
                        <td class="vertical-align-top border-{{$direction === "rtl" ? 'right' : 'left'}}">
                            <table>
                                <tbody>
                                @php($billingAddress = $order->billing_address_data)
                                @if(!empty((array) $billingAddress))
                                    <tr>
                                        <td class="px-2" colspan="3">
                                            <h5>{{ translate('Billing_address') }} <span class="fw-normal">({{ translate($billingAddress->address_type) }})</span>
                                            </h5>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Name') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1">
                                            {{ $billingAddress->contact_person_name }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Phone') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $billingAddress->phone }}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-nowrap">{{ translate('City_/_Zip') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $billingAddress->city }} {{ $billingAddress->zip }}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Address') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $billingAddress->address }}</span>
                                        </td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </td>
                        <td class="vertical-align-top border-{{$direction === "rtl" ? 'right' : 'left'}}">
                            <table>
                                <tbody>
                                @php($shipping = $order->shipping_address_data)
                                @if(!empty((array) $shipping))
                                    <tr>
                                        <td class="px-2" colspan="3">
                                            <h5>{{ translate('Shipping_address') }} <span class="fw-normal">({{ translate($shipping->address_type) }})</span>
                                            </h5>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Name') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1">
                                            {{ $shipping->contact_person_name }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Phone') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $shipping->phone }}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-nowrap">{{ translate('City_/_Zip') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $shipping->city }} {{ $shipping->zip }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1">{{ translate('Address') }}</td>
                                        <td class="pt-1 pb-1">:</td>
                                        <td class="px-2 pt-1 pb-1"><span
                                                class="text-dark">{{ $shipping->address }}</span>
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        <td class="px-2" colspan="3">
                                            <h5>{{ translate('Customer_Info') }}
                                            </h5>
                                        </td>
                                    </tr>
                                    @if($order->is_guest)
                                        <tr>
                                            <td class="px-2 pt-1 pb-1">{{ translate('Name') }}</td>
                                            <td class="pt-1 pb-1">:</td>
                                            <td class="px-2 pt-1 pb-1"><span
                                                    class="text-danger">{{translate('guest_User')}}</span></td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td class="px-2 pt-1 pb-1">{{ translate('Name') }}</td>
                                            <td class="pt-1 pb-1">:</td>
                                            <td class="px-2 pt-1 pb-1"><span
                                                    class="text-danger">{{ $order->customer !=null? $order->customer['f_name'].' '.$order->customer['l_name']:translate('name_not_found') }}</span>
                                            </td>
                                        </tr>
                                    @endif
                                    @if (isset($order->customer) && $order->customer['id']!=0)
                                        <tr>
                                            <td class="px-2 pt-1 pb-1">{{ translate('Email') }}</td>
                                            <td class="pt-1 pb-1">:</td>
                                            <td class="px-2 pt-1 pb-1"><span
                                                    class="text-dark">{{$order->customer !=null? $order->customer['email']: translate('email_not_found')}}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="px-2 pt-1 pb-1">{{ translate('Phone') }}</td>
                                            <td class="pt-1 pb-1">:</td>
                                            <td class="px-2 pt-1 pb-1"><span
                                                    class="text-dark">{{$order->customer !=null? $order->customer['phone']: translate('phone_not_found')}}</span>
                                            </td>
                                        </tr>
                                    @endif
                                @endif
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="border rounded">
        <table class="table">
            <thead>
            <tr>
                <th class="p-2 text-left">{{ translate('Item_Description') }}</th>
                <th class="p-2 text-center">{{ translate('Qty') }}</th>
                <th class="p-2 text-right">{{ translate('Unit_Price') }}</th>
                <th class="p-2 text-right">{{ translate('Total') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->details as $key=>$details)
                @php($productDetails = $details?->product ?? json_decode($details->product_details))
                <tr>
                    <td class="px-2 pt-1 pb-1">
                        <h6 class="m-0">{{$productDetails->name}}</h6>
                        @if($details['variant'])
                            <p class="m-0">{{ translate('variation')}} : {{$details['variant']}}</p>
                        @endif
                    </td>
                    <td class="px-2 pt-1 pb-1 text-center">{{$details->qty}}</td>
                    <td class="px-2 pt-1 pb-1 text-right">{{ webCurrencyConverter(amount: $details['price']) }}</td>
                    <td class="px-2 pt-1 pb-1 text-right">{{ webCurrencyConverter(amount: $details['price'] * $details['qty']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <table class="table">
            <tbody>
            <tr>
                <td colspan="4" class="p-2"></td>
            </tr>
            <tr>
                <td colspan="4" class="border-dashed-top p-2"></td>
            </tr>
            <tr>
                <td colspan="4">
                    <table class="table">
                        <tbody>
                        <tr>
                            <td class="text-right">
                                <table class="ml-auto">
                                    <tbody>
                                    @if($order['edited_status'] == 1)
                                        <tr>
                                            <td colspan="2" class="note text-left mb-3">#{{ translate('Note') }} : {{ translate('Total_bill_has_been_updated_after_the_edits') }}.</td>
                                        </tr>
                                    @endif
                                    @if($order['payment_method'] == 'cash_on_delivery' && $order['bring_change_amount'] > 0)
                                        <tr>
                                            <td colspan="2" class="note text-left mb-3">
                                                <span>* {{ translate('please_ensure_the_deliveryman_has') }} </span>
                                                <span class="fw-semibold">{{ $order['bring_change_amount'] }} {{ $order['bring_change_amount_currency'] ?? '' }}</span>
                                                <span> {{ translate('in_change_ready_for_the_customer') }}</span>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-left">{{ translate('Total_Item_Price') }}</td>
                                        <td class="px-2 pt-1 pb-1 text-right">
                                            <span class="text-dark">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['itemPrice']) }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-left">{{ translate('Item_Discount') }}</td>
                                        <td class="px-2 pt-1 pb-1 text-right">
                                            <span class="text-dark">-{{ webCurrencyConverter(amount: $orderTotalPriceSummary['itemDiscount']) }}</span>
                                        </td>
                                    </tr>
                                    @if ($order->order_type != 'default_type')
                                        <tr>
                                            <td class="px-2 pt-1 pb-1 text-left">{{ translate('extra_Discount') }}</td>
                                            <td class="px-2 pt-1 pb-1 text-right">
                                                <span class="text-dark">-{{ webCurrencyConverter(amount: $orderTotalPriceSummary['extraDiscount']) }}</span>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-left">{{ translate('Subtotal') }}</td>
                                        <td class="px-2 pt-1 pb-1 text-right">
                                            <span class="text-dark">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['subTotal']) }}</span>
                                        </td>
                                    </tr>
                                    @if($order->order_type == 'default_type' && $order?->is_shipping_free != 1)
                                        <tr>
                                            <td class="px-2 pt-1 pb-1 text-left">{{ translate('Shipping_fee') }}</td>
                                            <td class="px-2 pt-1 pb-1 text-right">
                                                <span class="text-dark">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['shippingTotal']) }}</span>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-left">{{ translate('Coupon_discount') }}</td>
                                        <td class="px-2 pt-1 pb-1 text-right">
                                            <span class="text-dark">-{{ webCurrencyConverter(amount: $orderTotalPriceSummary['couponDiscount']) }}</span>
                                        </td>
                                    </tr>
                                    @if($orderTotalPriceSummary['referAndEarnDiscount'] > 0)
                                        <tr>
                                            <td class="px-2 pt-1 pb-1 text-left">{{ translate('referral_discount') }}</td>
                                            <td class="px-2 pt-1 pb-1 text-right">
                                                <span class="text-dark">-{{ webCurrencyConverter(amount: $orderTotalPriceSummary['referAndEarnDiscount']) }}</span>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="px-2 pt-1 pb-1 text-left">{{ translate('Tax_fee') }}</td>
                                        <td class="px-2 pt-1 pb-1 text-right">
                                            <span class="text-dark">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['taxTotal']) }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="border-bottom px-2 pb-2"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="text-right">
                                            <table class="ml-auto w-100">
                                                <tbody>
                                                <tr>
                                                    <td class="px-2 pt-1 pb-1 text-left">
                                                        <h5 class="m-0">{{ translate('Total') }}
                                                            <span class="fs-10 fw-medium">
                                                                {{ $orderTotalPriceSummary['tax_model'] == 'include' ? '('.translate('Tax_:_Inc.').')' : '' }}
                                                            </span>
                                                        </h5>
                                                    </td>
                                                    <td class="px-2 pt-1 pb-1 text-right">
                                                        <h5 class="m-0">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['totalAmount']) }}</h5>
                                                    </td>
                                                </tr>
                                                @if($order['edited_status'] == 1 && $order?->latestEditHistory)
                                                    @if($order?->latestEditHistory?->order_due_amount > 0)
                                                        @if($order?->latestEditHistory?->order_due_payment_status == 'paid')
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Paid_Amount') }}
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_amount
                                                                                - $order?->latestEditHistory?->order_due_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Due_Amount_Paid_By') }}
                                                                        <span>
                                                                        ({{ ucwords(str_replace('_',' ',$order?->latestEditHistory?->order_due_payment_method)) }})
                                                                    </span>
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_due_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Total_Paid_Amount') }}
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                        @else
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Due_Amount') }}
                                                                        <span class="text-danger">
                                                                        ({{ translate($order?->latestEditHistory?->order_due_payment_status) }})
                                                                    </span>
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_due_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                        @endif
                                                    @endif


                                                    @if($order?->latestEditHistory?->order_return_amount > 0)
                                                        @if($order?->latestEditHistory?->order_return_payment_status == 'returned')
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Paid_Amount') }}
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_amount
                                                                                + $order?->latestEditHistory?->order_return_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Returned_By') }}
                                                                        <span>
                                                                        ({{ ucwords(str_replace('_',' ',$order?->latestEditHistory?->order_return_payment_method)) }})
                                                                    </span>
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_return_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                        @else
                                                            <tr>
                                                                <td class="px-2 pt-1 pb-1 text-left">
                                                                    <h5 class="m-0">
                                                                        {{ translate('Amount_To_Return') }}
                                                                        <span class="text-danger">
                                                                        ({{ translate($order?->latestEditHistory?->order_return_payment_status) }})
                                                                    </span>
                                                                    </h5>
                                                                </td>
                                                                <td class="px-2 pt-1 pb-1 text-right">
                                                                    <h5 class="m-0">
                                                                        {{ webCurrencyConverter(
                                                                            amount: $order?->latestEditHistory?->order_return_amount
                                                                        ) }}
                                                                    </h5>
                                                                </td>
                                                            </tr>
                                                        @endif
                                                    @endif
                                                @endif


                                                @if ($order->order_type == 'POS' || $order->order_type == 'pos')
                                                    <tr>
                                                        <td class="px-2 pt-1 pb-1 text-left">
                                                            <p class="m-0 text-dark">{{ translate('Paid_Amount') }}</p>
                                                        </td>
                                                        <td class="px-2 pt-1 pb-1 text-right">
                                                            <p class="m-0 text-dark">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['paidAmount']) }}</p>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="px-2 pt-1 pb-1 text-left">
                                                            <h5 class="m-0">{{ translate('change_amount') }}</h5>
                                                        </td>
                                                        <td class="px-2 pt-1 pb-1 text-right">
                                                            <h5 class="m-0">{{ webCurrencyConverter(amount: $orderTotalPriceSummary['changeAmount']) }}</h5>
                                                        </td>
                                                    </tr>
                                                @endif
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
    <div class="invoice-end-note">
        <table class="table mt-3">
            <tbody>
            <tr>
                <td class="border-dashed-top p-2"></td>
            </tr>
            <tr>
                <td class="text-center">
                    <h5 class="fw-normal m-0">{{ translate('thanks_for_the_purchase') }}</h5>
                </td>
            </tr>
            @if(isset($invoiceSettings['terms_and_condition']))
                <tr>
                    <td colspan="2" class="p-2"></td>
                </tr>
                <tr>
                    <td class="border-dashed-top p-2"></td>
                </tr>
                <tr>
                    <td class="px-2 pt-2">
                        <h5 class="m-0">{{ translate('terms_&_Conditions') }}</h5>
                        <p class="m-0">{{$invoiceSettings['terms_and_condition']}}</p>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
