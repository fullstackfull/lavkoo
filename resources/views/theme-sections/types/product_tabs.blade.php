{{-- One row, several sources, one line of tabs. Everything is rendered up
     front and switched with a class, so changing tab costs no request and works
     with JavaScript disabled (the first tab is simply the one that shows). --}}

@php
    $tabsId = 'ml-tabs-' . ($__section['id'] ?? $loop->index);
    $tabStyle = $s['style'] ?? 'rail';
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $tabs = [];
    foreach ($__section['blocks'] ?? [] as $tabBlock) {
        $tabSettings = $tabBlock['settings'] ?? [];
        if (empty($tabSettings['label'])) { continue; }
        $tabs[] = [
            'label' => $tabSettings['label'],
            'products' => $__resolver->products($tabSettings + ['limit' => (int) ($s['limit'] ?? 8)]),
        ];
    }
    $tabs = array_values(array_filter($tabs, fn ($tab) => $tab['products']->isNotEmpty()));
@endphp
@if ($tabs !== [])
    <div class="ml-sec-head ml-reveal">
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        </div>
        <div class="ml-tabs" data-ml-tabs="{{ $tabsId }}">
            @foreach ($tabs as $tabIndex => $tab)
                <button type="button" class="ml-tabs__btn {{ $tabIndex === 0 ? 'is-active' : '' }}"
                        data-ml-tab="{{ $tabIndex }}">{{ $tab['label'] }}</button>
            @endforeach
        </div>
    </div>
    @foreach ($tabs as $tabIndex => $tab)
        <div class="ml-tabs__panel {{ $tabIndex === 0 ? 'is-active' : '' }}"
             data-ml-tab-panel="{{ $tabsId }}-{{ $tabIndex }}" data-ml-tab-of="{{ $tabsId }}">
            <div class="{{ $tabStyle === 'grid' ? 'ml-grid' : 'ml-rail' }}">
                @foreach ($tab['products'] as $product)
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                @endforeach
            </div>
        </div>
    @endforeach
@endif
