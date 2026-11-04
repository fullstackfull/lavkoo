{{-- Quick inspection is a drawer; concentration work is a full page. Never stack two drawers
     (handoff 04 §20, 01 cross-screen rule 4). --}}
@props(['title', 'id' => 'sc-drawer', 'fullPageUrl' => null])
<div class="sc-scrim sc-scrim--drawer" data-sc-drawer-scrim="{{ $id }}" hidden></div>
<aside class="sc-drawer" id="{{ $id }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" hidden>
    <header class="sc-drawer__head">
        @isset($badges)<div class="sc-row" style="gap:6px">{{ $badges }}</div>@endisset
        <div style="min-width:0;flex:1 1 auto">
            <h5 class="sc-drawer__title" id="{{ $id }}-title">{{ $title }}</h5>
            @isset($sub)<div class="sc-muted" style="font-size:11px">{{ $sub }}</div>@endisset
        </div>
        <button type="button" class="sc-icon-btn" data-sc-drawer-close aria-label="{{ translate('close') }}">
            <x-sc.icon name="x" :size="15" />
        </button>
    </header>
    <div class="sc-drawer__body">{{ $slot }}</div>
    @isset($actions)
        <footer class="sc-drawer__foot">
            {{ $actions }}
            <div class="sc-spacer"></div>
            @if ($fullPageUrl)
                <a href="{{ $fullPageUrl }}" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('open_full_page') }}</a>
            @endif
        </footer>
    @endisset
</aside>
