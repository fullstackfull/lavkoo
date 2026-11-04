{{-- Actions apply to the selection, never to the filter (handoff 04 §39). --}}
@props(['total' => null])
<div {{ $attributes->merge(['class' => 'sc-bulk-bar']) }} data-sc-bulk-bar hidden>
    <span><span data-sc-bulk-count class="sc-num">0</span> {{ translate('selected') }}</span>
    <span class="sc-bulk-bar__divider"></span>
    {{ $slot }}
    <div class="sc-spacer"></div>
    <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm" data-sc-bulk-clear>{{ translate('clear') }}</button>
</div>
