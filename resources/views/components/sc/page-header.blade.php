{{-- Eyebrow equals the rail group name; breadcrumbs replace it on detail screens (handoff 02 §4). --}}
@props(['title', 'eyebrow' => null, 'sub' => null, 'back' => null, 'crumbs' => []])
<header {{ $attributes->merge(['class' => 'sc-page-header']) }}>
    @if ($back)
        <a href="{{ $back }}" class="sc-icon-btn" aria-label="{{ translate('back') }}" style="margin-bottom:2px">
            <x-sc.icon :name="\App\Services\SellerCenter\Shell::backGlyph()" :size="15" />
        </a>
    @endif
    <div class="sc-page-header__titles">
        @if (!empty($crumbs))
            <nav class="sc-breadcrumb" aria-label="{{ translate('breadcrumb') }}">
                @foreach ($crumbs as $index => $crumb)
                    @if ($index > 0)<span class="sc-breadcrumb__sep">/</span>@endif
                    @if (!empty($crumb['href']) && $index < count($crumbs) - 1)
                        <a href="{{ $crumb['href'] }}" style="color:inherit">{{ $crumb['label'] }}</a>
                    @else
                        <span class="sc-breadcrumb__current">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
        @elseif ($eyebrow)
            <div class="sc-eyebrow">{{ $eyebrow }}</div>
        @endif
        <h4 class="sc-page-title">{{ $title }}</h4>
        @if ($sub)<div class="sc-page-sub">{{ $sub }}</div>@endif
    </div>
    <div class="sc-spacer"></div>
    @isset($actions)<div class="sc-page-header__actions">{{ $actions }}</div>@endisset
</header>
