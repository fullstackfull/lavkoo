@props(['id', 'title' => null, 'width' => null])
{{-- Edit beside the context instead of navigating away from it. Below 640px it
     becomes a bottom sheet, because a 460px side panel on a 390px screen is not
     a panel. --}}
<div class="k-drawer-backdrop" data-k-drawer-backdrop="{{ $id }}"></div>
<aside id="{{ $id }}" class="k-drawer" role="dialog" aria-modal="true"
       @if ($title) aria-label="{{ $title }}" @endif
       @if ($width) style="inline-size:min({{ $width }},100vw)" @endif
       {{ $attributes }}>
    <header class="k-drawer__head">
        <h2 class="k-drawer__title">{{ $title }}</h2>
        <button type="button" class="k-btn k-btn--ghost k-btn--icon" data-k-drawer-close
                aria-label="{{ translate('close') }}">
            <x-k.icon name="close" :size="16" />
        </button>
    </header>
    <div class="k-drawer__body">{{ $slot }}</div>
    @isset($footer)<div class="k-drawer__foot">{{ $footer }}</div>@endisset
</aside>
