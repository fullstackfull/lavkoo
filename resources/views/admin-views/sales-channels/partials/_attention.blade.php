{{-- What needs a person, each with the link that resolves it. Expects $attention (HubSummary::attention()). --}}
<x-k.card :title="translate('needs_attention')" class="mb-3">
    @if ($attention === [])
        <p class="fs-12 text-muted mb-0">{{ translate('nothing_needs_attention') }}</p>
    @else
        <div class="row g-3">
            @foreach ($attention as $section)
                <div class="col-lg-6">
                    <div class="fw-semibold mb-1">{{ $section['title'] }} <x-k.badge tone="warning" :dot="false">{{ $section['total'] }}</x-k.badge></div>
                    <ul class="list-unstyled fs-12 mb-1 d-flex flex-column gap-1">
                        @foreach ($section['items'] as $item)
                            <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@if ($item['detail'] !== '') <span class="text-muted text-break">· {{ $item['detail'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                    @if ($section['moreUrl'] && $section['total'] > count($section['items']))
                        <a class="fs-12" href="{{ $section['moreUrl'] }}">{{ translate('see_all') }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-k.card>
