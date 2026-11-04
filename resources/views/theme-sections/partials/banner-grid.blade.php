{{-- Equal tiles in a responsive grid — the classic promotional row, with an optional text overlay. --}}
@php
    $cards = array_values(array_filter($cards ?? [], fn ($card) => !empty($card['image']) || !empty($card['title'])));
    $columns = max(1, (int) ($columns ?? 2));
    $gap = max(0, (int) ($gap ?? 24));
    $ratio = $settings['ratio'] ?? 'wide';
    $showText = (bool) ($settings['overlay'] ?? true);
    $aspect = match ($ratio) {
        'square'   => '1 / 1',
        'portrait' => '3 / 4',
        'auto'     => 'auto',
        default    => '16 / 9',
    };
@endphp

@php
    // Three arrangements of the same tiles: a grid, a scrolling rail for more banners than fit, and
    // an overlapping stagger that reads as a composition instead of a table.
    $style = $style ?? ($settings['style'] ?? 'tiles');
@endphp

@php
    // swipe = one horizontally snap-scrolling row, the same gesture the mosaic offers, available to
    // every banner section that shows more than one card. Unlike 'rail' (which scrolls only when
    // the tiles overflow), swipe is a deliberate carousel: fixed card width, snap points, touch.
    $swipe = $style === 'swipe';
    $rotateMs = max(1500, (int) ($settings['rotate_ms'] ?? 4000));
@endphp

@if (count($cards))
    <div class="{{ $swipe ? 'ml-mswipe' : ($style === 'rail' ? 'ml-rail' : 'ml-mosaic') }} ml-banners--{{ $style }}"
         @if ($swipe)
             style="--ml-sh:{{ max(120, (int) ($settings['height'] ?? 240)) }}px;gap:{{ $gap }}px"
         @elseif ($style !== 'rail')
             style="grid-template-columns:repeat(var(--tb-cols,{{ $columns }}),minmax(0,1fr));gap:{{ $gap }}px"
         @endif>
        @foreach ($cards as $card)
            @php
                // Extra frames travel with the card everywhere, not just in the mosaic: a banner
                // with three pictures crossfades wherever it is placed.
                $frames = array_values(array_filter($card['images'] ?? [$card['image'] ?? null]));
                $shape = in_array($card['span'] ?? null, ['small', 'square', 'wide', 'tall', 'large', 'strip'], true)
                    ? $card['span'] : null;
            @endphp
            <a class="ml-tile ml-reveal {{ count($frames) > 1 ? 'ml-tile--frames' : '' }} {{ $swipe && $shape ? 'ml-tile--' . $shape : '' }}"
               data-delay="{{ $loop->index % 6 }}" @if (count($frames) > 1) data-rotate="{{ $rotateMs }}" @endif
               href="{{ ($card['link'] ?? null) ?: 'javascript:void(0)' }}"
               style="{{ $aspect !== 'auto' && !$swipe ? 'aspect-ratio:' . $aspect . ';' : '' }}">
                @foreach ($frames === [] ? [$placeholder] : $frames as $frame)
                    <img src="{{ $frame }}" alt="{{ $card['title'] ?? '' }}" loading="lazy"
                         class="{{ count($frames) > 1 ? 'ml-tile__frame ' . ($loop->first ? 'is-on' : '') : '' }}">
                @endforeach
                @if (!empty($card['badge']))<span class="ml-tile__badge">{{ $card['badge'] }}</span>@endif
                @if ($showText && (!empty($card['title']) || !empty($card['subtitle'])))
                    <span class="ml-tile__scrim"></span>
                    <span class="ml-tile__body">
                        @if (!empty($card['title']))<h4>{{ $card['title'] }}</h4>@endif
                        @if (!empty($card['subtitle']))<p>{{ $card['subtitle'] }}</p>@endif
                        @if (!empty($card['button_text']))<span class="ml-btn ml-btn-light">{{ $card['button_text'] }}</span>@endif
                    </span>
                @endif
            </a>
        @endforeach
    </div>
@endif
