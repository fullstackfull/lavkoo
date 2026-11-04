{{-- Shop by interest: big tiles that land on a ready-made filtered page. --}}

@if (count($blocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-reveal">
            <div>
                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            </div>
        </div>
    @endif
    @php $interestStyle = $s['style'] ?? 'tiles'; @endphp
    <div class="{{ $interestStyle === 'rail' ? 'ml-rail' : ($interestStyle === 'circles' ? 'ml-cat-chips ml-interests--circles' : 'ml-grid') }}">
        @foreach ($blocks as $tile)
            @php $tileOverlay = max(0, min(90, (int) ($tile['overlay'] ?? 35))) / 100; @endphp
            <a class="ml-tile ml-interest ml-interest--{{ $interestStyle }} ml-reveal" data-delay="{{ $loop->index % 6 }}"
               href="{{ $tile['link'] ?: 'javascript:void(0)' }}"
               style="color:{{ $tile['text_color'] ?: '#fff' }};min-height:var(--tb-h,260px)">
                <img src="{{ $tile['image'] ?: $__placeholder }}" alt="{{ $tile['title'] ?? '' }}" loading="lazy">
                <span class="ml-tile__scrim" style="background:linear-gradient(180deg,rgba(0,0,0,{{ $tileOverlay / 3 }}),rgba(0,0,0,{{ $tileOverlay }}))"></span>
                <span class="ml-tile__body">
                    @if (!empty($tile['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $tile['eyebrow'] }}</span>@endif
                    @if (!empty($tile['title']))<h4>{{ $tile['title'] }}</h4>@endif
                    @if (!empty($tile['subtitle']))<p>{{ $tile['subtitle'] }}</p>@endif
                </span>
            </a>
        @endforeach
    </div>
@endif
