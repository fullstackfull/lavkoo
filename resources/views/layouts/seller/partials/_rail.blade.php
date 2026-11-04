{{-- Clicking a rail group navigates to its first panel item and switches the panel. It never
     opens a flyout on desktop (handoff 02 §2). --}}
<nav class="sc-rail" aria-label="{{ translate('sections') }}">
    @foreach ($scNav as $group)
        <a class="sc-rail__item{{ $scActive['group'] === $group['key'] ? ' is-active' : '' }}"
           href="{{ $group['items'][0]['href'] ?? '#' }}"
           title="{{ translate($group['label']) }}"
           aria-label="{{ translate($group['label']) }}"
           @if ($scActive['group'] === $group['key']) aria-current="page" @endif>
            <x-sc.icon :name="$group['icon']" :size="18" />
            @if (!empty($group['alert']))<span class="sc-rail__dot sc-rail__dot--{{ $group['alert'] }}"></span>@endif
        </a>
    @endforeach
</nav>
