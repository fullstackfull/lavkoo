{{-- Branches: where the shop physically is, when it opens, how to get there. --}}

@if (count($rawBlocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-reveal">
            <div>
                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            </div>
        </div>
    @endif
    @php $branchStyle = $s['style'] ?? 'cards'; @endphp
    {{-- A pharmacy with three branches wants cards; one with twenty wants a
         list it can scan for the nearest name. --}}
    <div class="{{ $branchStyle === 'list' ? 'ml-branches--list' : 'ml-grid' }}">
        @foreach ($rawBlocks as $branchBlock)
            @php $branch = $branchBlock['settings'] ?? []; @endphp
            @if (!empty($branch['title']))
                <article class="ml-branch ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    <h4>{{ $branch['title'] }}</h4>
                    @if (!empty($branch['address']))
                        <p><i class="fi fi-rr-marker"></i>{{ $branch['address'] }}</p>
                    @endif
                    @if (!empty($branch['hours']))
                        <p><i class="fi fi-rr-clock"></i>{{ $branch['hours'] }}</p>
                    @endif
                    @if (!empty($branch['phone']))
                        <p><i class="fi fi-rr-phone-call"></i><a class="direction-ltr" href="tel:{{ $branch['phone'] }}">{{ $branch['phone'] }}</a></p>
                    @endif
                    @if (!empty($branch['link']))
                        <a class="ml-btn ml-btn-light" href="{{ $branch['link'] }}" target="_blank" rel="noopener">{{ translate('open_in_maps') }}</a>
                    @endif
                </article>
            @endif
        @endforeach
    </div>
@endif
