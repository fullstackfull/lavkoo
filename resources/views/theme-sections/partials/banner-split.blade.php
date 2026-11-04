{{-- Editorial split panel: image on one side, copy on the other. The side is per panel, so
     stacking two panels gives the alternating layout luxury beauty stores use. --}}
@php
    $cards = array_values(array_filter($cards ?? [], fn ($card) => !empty($card['image']) || !empty($card['title'])));
    $height = max(160, (int) ($settings['height'] ?? 460));
    $gap = max(0, (int) ($gap ?? 0));
@endphp

@if (count($cards))
    <div class="d-flex flex-column" style="gap:{{ $gap }}px">
        @foreach ($cards as $card)
            @php $reversed = ($card['media_side'] ?? 'start') === 'end'; @endphp
            <div class="ml-split ml-reveal {{ $reversed ? 'is-reversed' : '' }}" data-delay="{{ $loop->index % 6 }}">
                <div class="ml-split__media" style="min-height:var(--tb-h,{{ $height }}px)">
                    <img src="{{ ($card['image'] ?? null) ?: $placeholder }}" alt="{{ $card['title'] ?? '' }}" loading="lazy">
                </div>
                <div class="ml-split__body" @if (!empty($card['background'])) style="background:{{ $card['background'] }}" @endif>
                    @if (!empty($card['eyebrow']))<span class="ml-eyebrow">{{ $card['eyebrow'] }}</span>@endif
                    @if (!empty($card['title']))<h3>{{ $card['title'] }}</h3>@endif
                    @if (!empty($card['subtitle']))<p>{{ $card['subtitle'] }}</p>@endif
                    @if (!empty($card['link']))
                        <a href="{{ $card['link'] }}" class="ml-btn">{{ ($card['button_text'] ?? null) ?: translate('discover') }}</a>
                    @endif
                </div>

                @include('theme-sections.partials._card-link', [
                    'link' => $card['link'] ?? null,
                    'label' => $card['title'] ?? null,
                    'bannerId' => $card['banner_id'] ?? null,
                ])
            </div>
        @endforeach
    </div>
@endif
