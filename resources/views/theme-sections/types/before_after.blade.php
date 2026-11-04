{{-- Before / after: a slider the customer drags across two photos. --}}

@if (count($rawBlocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-sec-head--center ml-reveal">
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            <div class="ml-rule"></div>
        </div>
    @endif
    <div class="ml-grid">
        @foreach ($rawBlocks as $pairBlock)
            @php $pair = $pairBlock['settings'] ?? []; @endphp
            @if (!empty($pair['image']) && !empty($pair['after']))
                <figure class="ml-ba ml-reveal" data-delay="{{ $loop->index % 6 }}"
                        style="height:var(--tb-h,360px)">
                    <img class="ml-ba__before" src="{{ $pair['image'] }}" alt="{{ translate('before') }}" loading="lazy">
                    <span class="ml-ba__after" style="width:50%">
                        <img src="{{ $pair['after'] }}" alt="{{ translate('after') }}" loading="lazy">
                    </span>
                    <input type="range" min="0" max="100" value="50" class="ml-ba__range"
                           aria-label="{{ translate('before_and_after') }}">
                    <span class="ml-ba__tag ml-ba__tag--before">{{ translate('before') }}</span>
                    <span class="ml-ba__tag ml-ba__tag--after">{{ translate('after') }}</span>
                    @if (!empty($pair['title']) || !empty($pair['caption']))
                        <figcaption>
                            @if (!empty($pair['title']))<b>{{ $pair['title'] }}</b>@endif
                            @if (!empty($pair['caption']))<span>{{ $pair['caption'] }}</span>@endif
                        </figcaption>
                    @endif
                </figure>
            @endif
        @endforeach
    </div>
@endif
