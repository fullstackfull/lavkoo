{{-- Full-bleed campaign strip with an optional parallax background — the wide statement banner. --}}
@php
    $card = $card ?? [];
    $height = max(140, (int) ($settings['height'] ?? 320));
    $overlay = max(0, min(90, (int) ($settings['overlay'] ?? 45))) / 100;
    $textColor = $settings['text_color'] ?? '#ffffff';
    $parallax = (bool) ($settings['parallax'] ?? true);
    $image = $card['image'] ?? null;
@endphp

@if (!empty($image) || !empty($card['title']))
    <div class="ml-strip ml-reveal {{ $parallax ? 'is-parallax' : '' }}"
         style="min-height:var(--tb-h,{{ $height }}px);color:{{ $textColor }}">
        <div class="ml-strip__bg" style="background-image:url('{{ $image ?: $placeholder }}')"></div>
        <div class="ml-strip__scrim" style="opacity:{{ $overlay }}"></div>
        <div class="ml-strip__body">
            @if (!empty($card['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $card['eyebrow'] }}</span>@endif
            @if (!empty($card['title']))<h3>{{ $card['title'] }}</h3>@endif
            @if (!empty($card['subtitle']))<p>{{ $card['subtitle'] }}</p>@endif
            @if (!empty($card['link']))
                <a href="{{ $card['link'] }}" class="ml-btn ml-btn-light">{{ ($card['button_text'] ?? null) ?: translate('shop_now') }}</a>
            @endif
        </div>

        @include('theme-sections.partials._card-link', [
            'link' => $card['link'] ?? null,
            'label' => $card['title'] ?? null,
            'bannerId' => $card['banner_id'] ?? null,
        ])
    </div>
@endif
