{{-- One wireframe per placement. Plain boxes: grey is ordinary page content,
     the tinted block is the banner being explained. --}}
@php($bannerLabel = translate('banner'))

@switch($wire)
    @case('main')
        <div class="bp-wire">
            <div class="bp-row"><div class="bp-box bp-box--text"></div></div>
            <div class="bp-banner bp-banner--tall">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
            </div>
        </div>
        @break

    @case('strip')
        <div class="bp-wire">
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
        </div>
        @break

    @case('footer')
        <div class="bp-wire">
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row"><div class="bp-box" style="min-height:22px"></div></div>
        </div>
        @break

    @case('popup')
        <div class="bp-wire" style="position: relative;">
            <div class="bp-row"><div class="bp-box bp-box--text"></div></div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
            <div class="bp-row"><div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div></div>
            <div class="bp-banner" style="position:absolute; inset:22% 22%; min-height:0;">{{ $bannerLabel }}</div>
        </div>
        @break

    @case('promo')
        <div class="bp-wire">
            <div class="bp-row"><div class="bp-box bp-box--text"></div></div>
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-banner bp-banner--half">{{ $bannerLabel }}</div>
                <div class="bp-banner bp-banner--half">{{ $bannerLabel }}</div>
            </div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
        </div>
        @break

    @case('section')
        <div class="bp-wire">
            <div class="bp-row"><div class="bp-box bp-box--text"></div></div>
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
            </div>
            <div class="bp-row"><div class="bp-box bp-box--text"></div></div>
        </div>
        @break

    @case('category')
        <div class="bp-wire">
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
            <div class="bp-row">
                <div class="bp-box bp-box--tall" style="max-width:28%"></div>
                <div class="bp-box bp-box--tall"></div>
            </div>
        </div>
        @break

    @case('brand')
        <div class="bp-wire">
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row">
                <div class="bp-box bp-box--chip"></div><div class="bp-box bp-box--chip"></div>
                <div class="bp-box bp-box--chip"></div>
            </div>
            <div class="bp-row">
                <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                <div class="bp-box bp-box--tile"></div>
            </div>
        </div>
        @break

    @case('layout_full')
        <div class="bp-wire">
            <div class="bp-banner">{{ $bannerLabel }}</div>
            <div class="bp-row"><div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div></div>
        </div>
        @break

    @case('layout_half')
        <div class="bp-wire">
            <div class="bp-row">
                <div class="bp-banner bp-banner--half">{{ $bannerLabel }}</div>
                <div class="bp-banner bp-banner--half">{{ $bannerLabel }}</div>
            </div>
            <div class="bp-row"><div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div></div>
        </div>
        @break

    @case('layout_slider')
        <div class="bp-wire">
            <div class="bp-banner bp-banner--tall">{{ $bannerLabel }} 1 / 2 / 3 →</div>
            <div class="bp-row justify-content-center" style="gap:4px">
                <div class="bp-box" style="max-width:10px; min-height:6px; border-radius:999px"></div>
                <div class="bp-box" style="max-width:10px; min-height:6px; border-radius:999px"></div>
                <div class="bp-box" style="max-width:10px; min-height:6px; border-radius:999px"></div>
            </div>
        </div>
        @break

    @case('mobile')
        <div class="bp-wire align-items-center">
            <div class="bp-phone">
                <div class="bp-box" style="min-height:8px"></div>
                <div class="bp-banner bp-banner--phone">{{ $bannerLabel }}</div>
                <div class="bp-row">
                    <div class="bp-box bp-box--tile"></div><div class="bp-box bp-box--tile"></div>
                </div>
            </div>
        </div>
        @break
@endswitch
