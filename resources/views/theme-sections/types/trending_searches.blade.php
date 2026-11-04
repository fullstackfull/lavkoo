{{-- What customers actually typed into the search box. Not a hand-written list
     of what the merchant hopes is popular — the shop's own measured demand,
     already filtered of bots and staff by the analytics rollup. --}}

@php $termStyle = $s['style'] ?? 'chips'; @endphp
@if (!empty($s['title']) || !empty($s['eyebrow']))
    <div class="ml-sec-head ml-reveal">
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        </div>
    </div>
@endif
@if ($termStyle === 'ranked')
    <ol class="ml-trend ml-reveal">
        @foreach ($searchTerms as $term)
            <li>
                <a href="{{ route('products', ['search' => $term->term]) }}">
                    <span class="ml-trend__rank">{{ $loop->iteration }}</span>
                    <span class="ml-trend__term">{{ $term->term }}</span>
                    <span class="ml-trend__count">{{ number_format((int) $term->searches) }}</span>
                </a>
            </li>
        @endforeach
    </ol>
@else
    <div class="ml-cat-chips ml-reveal">
        @foreach ($searchTerms as $term)
            <a class="ml-chip" href="{{ route('products', ['search' => $term->term]) }}">
                <span>{{ $term->term }}</span>
            </a>
        @endforeach
    </div>
@endif
