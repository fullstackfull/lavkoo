{{-- ⌘K / Ctrl+K, or `/` when no field has focus. Commands a role cannot perform are not listed
     (handoff 02 §5). --}}
<div class="sc-scrim sc-scrim--palette" data-sc-palette-scrim hidden></div>
<div class="sc-palette" data-sc-palette hidden role="dialog" aria-modal="true" aria-label="{{ translate('search') }}">
    <div class="sc-palette__panel">
        <div class="sc-palette__input-row">
            <x-sc.icon name="magnifying-glass" :size="15" />
            <input class="sc-palette__input" type="search" data-sc-palette-input autocomplete="off"
                   placeholder="{{ translate('search_orders_products_shipments_finance') }}" aria-label="{{ translate('search') }}">
            <kbd class="sc-kbd">esc</kbd>
        </div>
        <div class="sc-palette__results" data-sc-palette-results>
            <div class="sc-group-label">{{ translate('commands') }}</div>
            @foreach ($scCommands as $command)
                <a class="sc-palette__row" href="{{ $command['href'] }}" data-sc-palette-row>
                    <x-sc.icon :name="$command['icon'] ?? 'arrow-elbow-down-left'" :size="15" />
                    <span>{{ $command['label'] }}</span>
                    <span class="sc-palette__row-meta">{{ $command['group'] ?? '' }}</span>
                </a>
            @endforeach
        </div>
        <div class="sc-palette__foot">
            <span>↑↓ {{ translate('move') }}</span><span>↵ {{ translate('open') }}</span>
            <span>⌘1…9 {{ translate('sections') }}</span><span>esc {{ translate('close') }}</span>
        </div>
    </div>
</div>
