{{-- Real approved product reviews — the merchant chooses how many and the
     minimum rating; nothing is invented. --}}

@php $reviews = $__resolver->testimonials((int) ($s['limit'] ?? 3), (int) ($s['min_rating'] ?? 4)); @endphp
@if ($reviews->isNotEmpty())
    <div class="ml-sec-head ml-sec-head--center ml-reveal">
        <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('customer_voices') }}</span>
        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        <div class="ml-rule"></div>
    </div>
    @php $quoteStyle = $s['style'] ?? 'cards'; @endphp
    {{-- Cards read as endorsements, the wall reads as volume ("hundreds of
         people said this"), and compact fits under a product row without
         taking the page over. --}}
    <div class="ml-quotes ml-quotes--{{ $quoteStyle }}">
        @foreach ($reviews as $review)
            @php
                $reviewer = trim(($review->customer->f_name ?? '') . ' ' . ($review->customer->l_name ?? ''));
                $reviewer = $reviewer !== '' ? $reviewer : translate('a_customer');
            @endphp
            <article class="ml-quote ml-reveal" data-delay="{{ $loop->index % 6 }}">
                <div class="ml-quote__mark">&#10078;</div>
                <div class="ml-quote__stars">{{ str_repeat('★', max(1, min(5, (int) $review->rating))) }}</div>
                <p>{{ Str::limit($review->comment, 150) }}</p>
                <div class="ml-quote__who">
                    <span class="ml-quote__avatar">{{ mb_substr($reviewer, 0, 1) }}</span>
                    <div>
                        <b>{{ $reviewer }}</b>
                        <small>{{ $review->product?->name ? Str::limit($review->product->name, 32) : translate('verified_customer') }}</small>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
@endif
