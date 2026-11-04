{{-- Never render more than one group's items at a time: the full IA is 70+ destinations, the rail
     plus this panel keeps at most ~13 + 8 visible (handoff 02 §3). --}}
@php($scGroup = collect($scNav)->firstWhere('key', $scActive['group']) ?? ($scNav[0] ?? null))
@if ($scGroup)
    <nav class="sc-panel" aria-label="{{ translate($scGroup['label']) }}">
        <div class="sc-panel__head">{{ translate($scGroup['label']) }}</div>
        <div class="sc-panel__items">
            @foreach ($scGroup['items'] as $item)
                <a class="sc-panel__item{{ $scActive['item'] === $item['key'] ? ' is-active' : '' }}" href="{{ $item['href'] }}"
                   @if ($scActive['item'] === $item['key']) aria-current="page" @endif>
                    <span class="sc-panel__label">{{ translate($item['label']) }}</span>
                    @if (!empty($item['legacy']))
                        <x-sc.icon name="arrow-up-right" :size="11" class="sc-muted" />
                    @endif
                    <x-sc.count :value="$item['badgeValue']" :tone="$item['badgeToneValue']" />
                </a>
            @endforeach
        </div>
        <div class="sc-panel__foot">
            <a class="sc-panel__item" href="{{ \App\Services\SellerCenter\Shell::route('seller.settings.index') ?? url('vendor/shop/index') }}">
                <x-sc.icon name="gear-six" :size="14" /><span class="sc-panel__label">{{ translate('settings') }}</span>
            </a>
            <a class="sc-panel__item" href="{{ \App\Services\SellerCenter\Shell::route('seller.cases.index') ?? url('vendor/messages/index/customer') }}">
                <x-sc.icon name="lifebuoy" :size="14" /><span class="sc-panel__label">{{ translate('support') }}</span>
                <x-sc.count :value="$scCounts['cases_open'] ?? null" />
            </a>
        </div>
    </nav>
@endif
