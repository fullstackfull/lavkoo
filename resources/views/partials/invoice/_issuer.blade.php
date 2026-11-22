{{--
    The issuer block at the top right of an invoice: where the company that issued it is and what identifies it. Shared by the admin, vendor and customer
    invoices, which print the same block.

    An order that carries the company frozen when it was placed (GCC-13, see OrderInvoiceIssuer) prints that company: its name, address, tax and commercial
    registration numbers, each only when it has one. Every other order prints the shop's address and the business identity of the invoice settings, as it always
    did. Needs $order; reads $invoiceSettings of the invoice that includes it.
--}}
@use('App\Services\Storefront\OrderInvoiceIssuer')
@php($issuerLines = OrderInvoiceIssuer::lines($order))
@if($issuerLines === null)
    <p class="text-dark p-0">{{getWebConfig('shop_address') }}</p>
    @if(isset($invoiceSettings['business_identity_status']) && $invoiceSettings['business_identity_status'])
        <p class="text-dark p-0">
            <span class="fw-semibold">{{ $invoiceSettings['business_identity'] }}</span>
            : {{ $invoiceSettings['business_identity_value'] }}
        </p>
    @endif
@else
    @foreach($issuerLines as $issuerLine)
        <p class="text-dark p-0">
            @if($issuerLine['label'] !== null)
                <span class="fw-semibold">{{ translate($issuerLine['label']) }}</span>
                : {{ $issuerLine['value'] }}
            @else
                {{ $issuerLine['value'] }}
            @endif
        </p>
    @endforeach
@endif
