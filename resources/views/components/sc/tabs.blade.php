{{-- Saved views and issue views. Not for switching between unrelated screens (handoff 04 §16). --}}
@props(['tabs' => [], 'current' => null, 'inline' => false])
<nav {{ $attributes->merge(['class' => 'sc-tabs' . ($inline ? ' sc-tabs--inline' : '')]) }} role="tablist">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['href'] }}" role="tab"
           aria-selected="{{ ($tab['key'] ?? null) === $current ? 'true' : 'false' }}"
           class="sc-tab{{ ($tab['key'] ?? null) === $current ? ' is-active' : '' }}">
            {{ $tab['label'] }}
            @if (isset($tab['count']))<x-sc.count :value="$tab['count']" :tone="$tab['tone'] ?? 'neutral'" />@endif
        </a>
    @endforeach
    {{ $slot }}
</nav>
