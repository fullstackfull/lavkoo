{{-- Shop by what the customer can spend, which is how a lot of people actually
     shop — and the one entry point a category tree cannot offer. --}}

@if (count($blocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-reveal">
            <div>
                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            </div>
        </div>
    @endif
    <div class="ml-grid">
        @foreach ($__section['blocks'] ?? [] as $bandBlock)
            @php
                // Its own settings, not the banner-shaped card blockCards()
                // builds: a price band is a number pair, not a picture.
                $band = $bandBlock['settings'] ?? [];
                // Not (int): three of the six currencies are thousandths (KWD, BHD, OMR), so a band of half a dinar truncated to 0 made the
                // tile say "0+" and filtered the listing at nothing. The label and the query now use the same typed value, and a whole
                // number is unchanged -- 100.0 builds the same URL and formats the same way 100 did (F-10).
                $min = max(0, (float) ($band['min'] ?? 0));
                $max = max(0, (float) ($band['max'] ?? 0));
                $query = ['min_price' => $min] + ($max > 0 ? ['max_price' => $max] : []);
                $label = ($band['label'] ?? '') ?: ($max > 0
                    ? webCurrencyConverter(amount: $min) . ' - ' . webCurrencyConverter(amount: $max)
                    : webCurrencyConverter(amount: $min) . '+');
            @endphp
            <a class="ml-price-tile ml-reveal" data-delay="{{ $loop->index % 6 }}"
               href="{{ route('products', $query) }}"
               @if (!empty($band['image'])) style="background-image:linear-gradient(140deg,rgba(20,8,46,.62),rgba(20,8,46,.28)),url('{{ $band['image'] }}')" @endif>
                <span class="ml-price-tile__label">{{ $label }}</span>
            </a>
        @endforeach
    </div>
@endif
