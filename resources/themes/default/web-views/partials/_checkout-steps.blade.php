@php($billingInputByCustomer = getWebConfig(name: 'billing_input_by_customer'))
{{-- $step: 1 cart, 2 shipping/billing, 3 payment. Steps before the current one
     are completed and link back; the current one is highlighted; later steps
     are inert. The old markup hardcoded the first two as completed in English,
     so every checkout page showed the same wrong progress. --}}
<div class="checkout-steps-custom">
    @foreach ([
        1 => ['label' => translate('cart'), 'url' => route('shop-cart')],
        2 => ['label' => translate('shipping') . ($billingInputByCustomer == 1 ? ' & ' . translate('billing') : ''), 'url' => route('checkout-details')],
        3 => ['label' => translate('payment'), 'url' => null],
    ] as $index => $item)
        <div class="step {{ $step > $index ? 'completed' : '' }} {{ $step == $index ? 'current' : '' }}">
            @if ($step > $index && $item['url'])
                <a href="{{ $item['url'] }}" class="step-circle fs-13" aria-label="{{ $item['label'] }}">
                    <i class="czi-check"></i>
                </a>
            @else
                <div class="step-circle fs-13" @if ($step == $index) aria-current="step" @endif>
                    <i class="czi-check"></i>
                </div>
            @endif
            <p class="m-0 fs-16 text-dark fw-medium">{{ $item['label'] }}</p>
        </div>
    @endforeach
</div>
