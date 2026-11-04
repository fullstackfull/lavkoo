{{--
    Page header: title on one side, actions on the other. Uses flex + gap (no directional margins),
    so it mirrors correctly in RTL without extra rules.

    Usage:
        <x-ui.page-header title="Theme_Management">
            <a href="..." class="btn btn-primary">{{ translate('add') }}</a>
        </x-ui.page-header>
--}}
@props(['title', 'subtitle' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'd-flex flex-wrap justify-content-between align-items-center gap-3 mb-3']) }}>
    <div>
        <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
            @if($icon)<i class="fi {{ $icon }}"></i>@endif
            {{ translate($title) }}
        </h2>
        @if($subtitle)
            <div class="text-muted small mt-1">{{ translate($subtitle) }}</div>
        @endif
    </div>
    @if(trim($slot ?? '') !== '')
        <div class="d-flex flex-wrap align-items-center gap-2">{{ $slot }}</div>
    @endif
</div>
