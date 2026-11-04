@props([
    'title' => null,
    'count' => null,
    'searchPlaceholder' => null,
    'searchName' => 'search',
    'searchValue' => null,
    'selectable' => false,
])
{{--
    The one collection surface, replacing 142 hand-rolled tables.

    Slots: `tabs` (saved views), `filters` (applied chips), `bulk` (actions shown
    while rows are selected), default (the table), `pager`.

    Filter state belongs in the URL — the search field posts a plain GET so a
    filtered view stays shareable and bookmarkable, which the current offcanvas
    filters are not.
--}}
<div {{ $attributes->merge(['class' => 'k-card k-view']) }} @if ($selectable) data-k-selectable @endif>
    @isset($tabs)
        <div class="k-view__toolbar" style="padding-block-end:0">
            <nav class="k-tabs k-grow">{{ $tabs }}</nav>
        </div>
    @endisset

    <div class="k-view__toolbar">
        @if ($title)
            <h3 class="k-card__title">
                {{ $title }}
                @if ($count !== null)<span class="k-tab__count k-num">{{ $count }}</span>@endif
            </h3>
        @endif

        <form method="get" class="k-view__toolbar-grow" role="search">
            @foreach (request()->except([$searchName, 'page']) as $key => $value)
                @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
            @endforeach
            <div class="k-search">
                <x-k.icon name="search" :size="15" />
                <input type="search" class="k-input" name="{{ $searchName }}"
                       {{-- requestString, not request(): htmlspecialchars() rejects an array, so
                            `?searchValue[]=x` took down every list screen built on this component.
                            The hidden fields above already guard with is_scalar; this one did not. --}}
                       value="{{ $searchValue ?? requestString($searchName) }}"
                       placeholder="{{ $searchPlaceholder ?? translate('search') }}"
                       aria-label="{{ $searchPlaceholder ?? translate('search') }}">
            </div>
        </form>

        @isset($actions)<div class="k-row">{{ $actions }}</div>@endisset
    </div>

    @isset($filters)<div class="k-view__chips">{{ $filters }}</div>@endisset

    @isset($bulk)
        <div class="k-bulk" data-k-bulk hidden>
            <span class="k-bulk__count"><span data-k-bulk-count class="k-num">0</span> {{ translate('selected') }}</span>
            <div class="k-bulk__actions">{{ $bulk }}</div>
        </div>
    @endisset

    <div class="k-table-wrap">{{ $slot }}</div>

    @isset($pager)<div class="k-pager">{{ $pager }}</div>@endisset
</div>
