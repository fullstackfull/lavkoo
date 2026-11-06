{{-- Shown at the payment step when the cart will be fulfilled from more than one source. --}}
@php($mixedNotice = app(\App\Services\Fulfillment\MixedProviderNotice::class)->forProducts($cartProductIds ?? []))
@if ($mixedNotice)
    <div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="note" data-mixed-provider-notice>
        <i class="tio-info-outined mt-1" aria-hidden="true"></i>
        <span class="fs-13">{{ $mixedNotice['message'] }}</span>
    </div>
@endif
