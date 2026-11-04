{{-- Live coupons as cards the customer copies with one tap. Only codes anyone
     can actually redeem are listed (see SectionDataResolver::coupons). --}}

    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-reveal">
            <div>
                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            </div>
        </div>
    @endif
    @php $couponStyle = $s['style'] ?? 'tickets'; @endphp
    <div class="{{ $couponStyle === 'strip' ? 'ml-coupon-strip' : 'ml-grid' }} ml-coupons--{{ $couponStyle }}">
        @foreach ($coupons as $coupon)
            <div class="ml-coupon ml-reveal" data-delay="{{ $loop->index % 6 }}">
                <div class="ml-coupon__value">
                    @if ($coupon->coupon_type === 'free_delivery')
                        {{ translate('free_delivery') }}
                    @elseif ($coupon->discount_type === 'percent')
                        {{ (int) $coupon->discount }}%
                    @else
                        {{ webCurrencyConverter(amount: $coupon->discount) }}
                    @endif
                </div>
                <div class="ml-coupon__body">
                    <b>{{ $coupon->title }}</b>
                    @if ($coupon->min_purchase > 0)
                        <small>{{ translate('minimum_purchase') }}: {{ webCurrencyConverter(amount: $coupon->min_purchase) }}</small>
                    @endif
                    <small>{{ translate('valid_until') }} {{ \Carbon\Carbon::parse($coupon->expire_date)->translatedFormat('d M Y') }}</small>
                </div>
                <button type="button" class="ml-coupon__code" data-ml-copy="{{ $coupon->code }}"
                        title="{{ translate('copy_code') }}">
                    <span>{{ $coupon->code }}</span>
                    <i class="fi fi-rr-copy"></i>
                </button>
            </div>
        @endforeach
    </div>
