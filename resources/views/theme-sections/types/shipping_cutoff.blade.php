{{-- "Order within X to have it shipped today", counting down to the merchant's
     own cut-off time. After the cut-off the section hides itself. --}}

    <div class="ml-cutoff {{ ($s['style'] ?? 'strip') === 'card' ? 'is-card' : '' }} ml-reveal"
         data-ml-countdown="{{ now()->addSeconds($secondsLeft)->getTimestamp() }}">
        <i class="fi fi-rr-truck-side"></i>
        <div>
            <b>{{ $s['title'] ?: translate('order_within') }}
                <span class="ml-cutoff__clock">
                    <span data-unit="hours">00</span>:<span data-unit="minutes">00</span>:<span data-unit="seconds">00</span>
                </span>
            </b>
            <small>{{ $s['subtitle'] ?: translate('to_have_it_shipped_today') }}</small>
        </div>
    </div>
