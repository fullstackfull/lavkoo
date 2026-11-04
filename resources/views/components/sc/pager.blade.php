{{-- Server-side always; page size persists per screen (handoff 04 §18). --}}
@props(['paginator' => null, 'sizes' => [25, 50, 100]])
@if ($paginator && $paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'sc-pager']) }}>
        <span>{{ translate('rows_per_page') }}</span>
        <form method="GET" style="display:inline-flex">
            @foreach (request()->except(['size', 'page']) as $name => $value)
                @if (!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <select name="size" class="sc-select" onchange="this.form.submit()" aria-label="{{ translate('rows_per_page') }}">
                @foreach ($sizes as $size)
                    <option value="{{ $size }}" @selected((int) request('size', $paginator->perPage()) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </form>
        <div class="sc-spacer"></div>
        <span class="sc-num">{{ \App\Services\SellerCenter\Copy::line('showing_range', [
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ]) }}</span>
        <a class="sc-icon-btn {{ $paginator->onFirstPage() ? 'is-disabled' : '' }}"
           href="{{ $paginator->onFirstPage() ? '#' : $paginator->previousPageUrl() }}"
           aria-label="{{ translate('previous_page') }}" @if ($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" style="opacity:.45;pointer-events:none" @endif>
            <x-sc.icon :name="\App\Services\SellerCenter\Shell::isRtl() ? 'caret-right' : 'caret-left'" :size="14" />
        </a>
        <a class="sc-icon-btn" href="{{ $paginator->hasMorePages() ? $paginator->nextPageUrl() : '#' }}"
           aria-label="{{ translate('next_page') }}" @if (!$paginator->hasMorePages()) aria-disabled="true" tabindex="-1" style="opacity:.45;pointer-events:none" @endif>
            <x-sc.icon :name="\App\Services\SellerCenter\Shell::isRtl() ? 'caret-left' : 'caret-right'" :size="14" />
        </a>
    </div>
@endif
