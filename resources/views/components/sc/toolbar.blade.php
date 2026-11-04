{{-- Fixed order on every screen: search → chips → Add filter → Clear all → spacer → count →
     Columns (handoff 05 B1). --}}
@props(['count' => null, 'searchUrl' => null, 'searchValue' => '', 'searchPlaceholder' => null, 'chips' => [], 'clearUrl' => null, 'filters' => []])
<div {{ $attributes->merge(['class' => 'sc-toolbar']) }}>
    @if ($searchUrl !== null)
        <form method="GET" action="{{ $searchUrl }}" class="sc-search" role="search">
            @foreach (request()->except(['q', 'page']) as $name => $value)
                @if (!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <span class="sc-search__glyph"><x-sc.icon name="magnifying-glass" :size="13" /></span>
            <input type="search" name="q" value="{{ $searchValue }}" class="sc-input"
                   placeholder="{{ $searchPlaceholder ?? translate('search') }}" aria-label="{{ $searchPlaceholder ?? translate('search') }}">
        </form>
    @endif

    @foreach ($chips as $chip)
        <x-sc.chip :key="$chip['label']" :value="$chip['value']" :remove="$chip['removeUrl']" :tone="$chip['tone'] ?? null" />
    @endforeach

    @if (!empty($filters))
        <div style="position:relative" data-sc-filter-root>
            <button type="button" class="sc-btn sc-btn--secondary sc-btn--sm" data-sc-filter-toggle>
                <x-sc.icon name="funnel" :size="12" />{{ translate('add_filter') }}
            </button>
            <div class="sc-menu sc-menu--filter" data-sc-filter-panel hidden>
                @foreach ($filters as $group => $fields)
                    <div class="sc-menu__group">{{ $group }}</div>
                    @foreach ($fields as $field)
                        <div data-sc-filter-field="{{ $field['key'] }}">
                            <button type="button" class="sc-menu__item" data-sc-filter-open>{{ $field['label'] }}</button>
                            <div class="sc-stack--tight" style="display:none;padding:4px 8px 8px" data-sc-filter-editor>
                                @if (($field['type'] ?? 'text') === 'enum' && !empty($field['options']))
                                    @foreach ($field['options'] as $option)
                                        <a class="sc-menu__item" href="{{ $option['href'] ?? '#' }}">
                                            @if (!empty($option['tone']))<x-sc.dot :tone="$option['tone']" :size="6" />@endif
                                            <span>{{ $option['label'] }}</span>
                                            @if (isset($option['count']))<span class="sc-spacer"></span><x-sc.count :value="$option['count']" />@endif
                                        </a>
                                    @endforeach
                                @else
                                    <form method="GET" action="{{ $field['action'] ?? url()->current() }}" class="sc-row">
                                        @foreach (request()->except([$field['key'], 'page']) as $name => $value)
                                            @if (!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                                        @endforeach
                                        <input class="sc-input" name="{{ $field['key'] }}" type="{{ ($field['type'] ?? 'text') === 'number' ? 'number' : (($field['type'] ?? '') === 'date' ? 'date' : 'text') }}"
                                               placeholder="{{ $field['label'] }}" aria-label="{{ $field['label'] }}">
                                        <button class="sc-btn sc-btn--secondary sc-btn--sm" type="submit">{{ translate('apply') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endif

    @if (count($chips) >= 2 && $clearUrl)
        <a href="{{ $clearUrl }}" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('clear_all') }}</a>
    @endif

    <div class="sc-spacer"></div>
    @if ($count !== null)<span class="sc-toolbar__count">{{ $count }}</span>@endif
    {{ $slot }}
</div>
