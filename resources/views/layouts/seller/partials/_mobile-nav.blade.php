{{-- 5 tabs carrying the mobile priorities; `More` opens the same drawer (handoff 02 §7). --}}
@php
    use App\Services\SellerCenter\Shell;

    /* The mobile priorities (handoff 10 A6); a tab whose screen has not shipped is omitted. */
    $scTabs = array_values(array_filter([
        ['key' => 'home', 'label' => translate('nav_home'), 'icon' => 'gauge', 'href' => Shell::route('seller.home')],
        ['key' => 'orders', 'label' => translate('nav_orders'), 'icon' => 'receipt', 'href' => Shell::route('seller.orders.index')],
        ['key' => 'operations', 'label' => translate('nav_issue_center'), 'icon' => 'activity', 'href' => Shell::route('seller.issues.index')],
        ['key' => 'inventory', 'label' => translate('nav_inventory'), 'icon' => 'stack', 'href' => Shell::route('seller.inventory.index')],
    ], static fn ($tab) => $tab['href'] !== null));
@endphp
<nav class="sc-mobile-nav" aria-label="{{ translate('sections') }}">
    @foreach ($scTabs as $tab)
        <a class="sc-mobile-nav__item{{ $scActive['group'] === $tab['key'] ? ' is-active' : '' }}" href="{{ $tab['href'] }}">
            <x-sc.icon :name="$tab['icon']" :size="18" />
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
    <button type="button" class="sc-mobile-nav__item" data-sc-nav-open>
        <x-sc.icon name="dots-three" :size="18" />
        <span>{{ translate('more') }}</span>
    </button>
</nav>

<div class="sc-scrim sc-scrim--drawer" data-sc-nav-scrim hidden></div>
<aside class="sc-nav-drawer" data-sc-nav-drawer hidden aria-label="{{ translate('sections') }}">
    @foreach ($scNav as $group)
        <details @if ($scActive['group'] === $group['key']) open @endif>
            <summary class="sc-panel__head" style="cursor:pointer;display:flex;align-items:center;gap:8px">
                <x-sc.icon :name="$group['icon']" :size="16" />{{ translate($group['label']) }}
            </summary>
            <div class="sc-panel__items">
                @foreach ($group['items'] as $item)
                    <a class="sc-panel__item{{ $scActive['item'] === $item['key'] ? ' is-active' : '' }}" href="{{ $item['href'] }}">
                        <span class="sc-panel__label">{{ translate($item['label']) }}</span>
                        <x-sc.count :value="$item['badgeValue']" :tone="$item['badgeToneValue']" />
                    </a>
                @endforeach
            </div>
        </details>
    @endforeach
</aside>
