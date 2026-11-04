{{-- Asymmetric mosaic: each tile chooses its span (small / wide / tall / large), so one large
     campaign image can sit beside two smaller category tiles. --}}
@php
    $cards = array_values(array_filter($cards ?? [], fn ($card) => !empty($card['image']) || !empty($card['title'])));
    $rowHeight = max(120, (int) ($settings['height'] ?? 240));
    $gap = max(0, (int) ($gap ?? 16));

    // Locked mode: the composition scales like ONE PICTURE instead of reflowing. The ratio pins
    // each grid cell to the shape it has at the 1140px design width — rows then track the real
    // container width (container queries), so a phone shows the same four-column arrangement
    // smaller, and a wide monitor shows it larger, never rearranged. 1140 is Bootstrap's xl
    // container, the width the merchant composed against in the builder preview.
    $locked = !empty($settings['layout_lock']);
    $cellRatio = round($rowHeight / max(1, (1140 - 3 * $gap) / 4), 4);
@endphp

@php
    // swipe = ONE horizontally swipeable, snap-scrolling row: small squares side by side that
    // scroll, in the merchant's words. Shapes set each card's width against the shared row height.
    $swipe = ($settings['display'] ?? 'grid') === 'swipe';
    $rotateMs = max(1500, (int) ($settings['rotate_ms'] ?? 4000));
    $shapes = ['small', 'square', 'wide', 'tall', 'large', 'strip'];
@endphp

@if (count($cards))
    <div class="{{ $locked && !$swipe ? 'ml-mosaic-lockwrap' : '' }}">
    <div class="{{ $swipe ? 'ml-mswipe' : 'ml-mosaic ' . ($locked ? 'ml-mosaic--locked' : '') }}"
         style="{{ $swipe ? '--ml-sh:' . $rowHeight . 'px;' : 'grid-auto-rows:var(--tb-h,' . $rowHeight . 'px);' }}gap:{{ $gap }}px;--ml-mgap:{{ $gap }}px;--ml-mratio:{{ $cellRatio }}">
        @foreach ($cards as $card)
            @php
                $span = in_array($card['span'] ?? 'small', $shapes, true) ? $card['span'] : 'small';
                $overlay = max(0, min(90, (int) ($card['overlay'] ?? 30))) / 100;
                $frames = array_values(array_filter($card['images'] ?? [$card['image'] ?? null]));
            @endphp
            <a class="ml-tile ml-tile--{{ $span }} ml-reveal {{ count($frames) > 1 ? 'ml-tile--frames' : '' }}"
               data-delay="{{ $loop->index % 6 }}" @if (count($frames) > 1) data-rotate="{{ $rotateMs }}" @endif
               href="{{ ($card['link'] ?? null) ?: 'javascript:void(0)' }}"
               style="color:{{ ($card['text_color'] ?? null) ?: '#ffffff' }}">
                @foreach ($frames === [] ? [$placeholder] : $frames as $frame)
                    <img src="{{ $frame }}" alt="{{ $card['title'] ?? '' }}" loading="lazy"
                         class="ml-tile__frame {{ $loop->first ? 'is-on' : '' }}">
                @endforeach
                <span class="ml-tile__scrim" style="background:linear-gradient(180deg,rgba(0,0,0,{{ $overlay / 3 }}),rgba(0,0,0,{{ $overlay }}))"></span>
                <span class="ml-tile__body">
                    @if (!empty($card['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $card['eyebrow'] }}</span>@endif
                    @if (!empty($card['title']))<h4>{{ $card['title'] }}</h4>@endif
                    @if (!empty($card['button_text']))<span class="ml-btn ml-btn-light">{{ $card['button_text'] }}</span>@endif
                </span>
            </a>
        @endforeach
    </div>
    </div>
@endif
