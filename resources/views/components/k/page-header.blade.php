@props(['title', 'subtitle' => null])
<header {{ $attributes->merge(['class' => 'k-page-head']) }}>
    <div>
        <h1 class="k-page-head__title">{{ $title }}</h1>
        @if ($subtitle)<p class="k-page-head__sub">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="k-page-head__actions">{{ $actions }}</div>@endisset
</header>
