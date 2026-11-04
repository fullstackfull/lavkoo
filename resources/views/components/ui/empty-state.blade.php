{{--
    Empty state — one consistent treatment instead of ad-hoc "No data found" cells.

    Usage:
        <x-ui.empty-state title="no_redirects_yet" />
        <x-ui.empty-state title="no_products" message="add_your_first_product" icon="fi-rr-box">
            <a href="..." class="btn btn-sm btn-primary">{{ translate('add_product') }}</a>
        </x-ui.empty-state>
--}}
@props([
    'title'   => 'no_data_found',
    'message' => null,
    'icon'    => 'fi-rr-inbox',
])

<div {{ $attributes->merge(['class' => 'text-center py-5 px-3']) }}>
    <i class="fi {{ $icon }} d-block mb-2 text-muted" style="font-size:1.75rem;line-height:1"></i>
    <div class="fw-bold mb-1">{{ translate($title) }}</div>
    @if($message)
        <div class="text-muted small mb-3">{{ translate($message) }}</div>
    @endif
    @if(trim($slot ?? '') !== '')
        <div class="d-flex justify-content-center gap-2">{{ $slot }}</div>
    @endif
</div>
