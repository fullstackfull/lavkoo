{{-- Trust badges in two skins: light boxed cards, or the dark panel from the
     mockup ("display style" in the builder). --}}

@php
    $uspStyle = $s['style'] ?? 'boxed';
    $boxed = $uspStyle !== 'plain';
@endphp
@if (count($blocks))
    <div class="ml-grid {{ $uspStyle === 'dark' ? 'ml-usp-dark' : '' }}">
        @foreach ($blocks as $card)
            <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                <a class="ml-usp {{ $boxed ? 'is-boxed' : '' }}" href="{{ $card['link'] ?: 'javascript:void(0)' }}">
                    <span class="ml-usp__icon">
                        @if (!empty($card['image']))
                            <img src="{{ $card['image'] }}" alt="" width="22" height="22" loading="lazy">
                        @else
                            @include('theme-sections.partials.usp-icon', ['icon' => $card['icon'] ?? 'shipping'])
                        @endif
                    </span>
                    <span>
                        <strong>{{ $card['title'] }}</strong>
                        <span>{{ $card['subtitle'] }}</span>
                    </span>
                </a>
            </div>
        @endforeach
    </div>
@endif
