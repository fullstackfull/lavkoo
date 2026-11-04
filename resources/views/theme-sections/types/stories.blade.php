{{-- Stories: vertical cards that open full screen, each linking somewhere. --}}

@if (count($blocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-reveal">
            <div>
                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            </div>
        </div>
    @endif
    @php $storyStyle = $s['style'] ?? 'bubbles'; @endphp
    {{-- Bubbles are the social-media shape everyone already knows how to
         tap; cards give the title room when the story is editorial. --}}
    <div class="ml-stories ml-stories--{{ $storyStyle }} ml-reveal">
        @foreach ($rawBlocks as $storyIndex => $storyBlock)
            @php $story = $storyBlock['settings'] ?? []; @endphp
            @if (!empty($story['image']) || !empty($story['video']))
                <button type="button" class="ml-story-dot" data-ml-story="{{ $storyIndex }}">
                    <span class="ml-story-dot__ring">
                        <img src="{{ $story['image'] ?: $__placeholder }}" alt="{{ $story['title'] ?? '' }}" loading="lazy">
                    </span>
                    <small>{{ Str::limit($story['title'] ?? '', 14) }}</small>
                </button>
            @endif
        @endforeach
    </div>

    <div class="ml-story-viewer" hidden>
        <button type="button" class="ml-story-viewer__close" aria-label="{{ translate('close') }}">&times;</button>
        <div class="ml-story-viewer__stage">
            @foreach ($rawBlocks as $storyIndex => $storyBlock)
                @php $story = $storyBlock['settings'] ?? []; @endphp
                @if (!empty($story['image']) || !empty($story['video']))
                    <figure class="ml-story-slide" data-ml-story-slide="{{ $storyIndex }}" hidden>
                        @if (!empty($story['video']))
                            <video src="{{ $story['video'] }}" playsinline muted loop controls></video>
                        @else
                            <img src="{{ $story['image'] }}" alt="{{ $story['title'] ?? '' }}">
                        @endif
                        <figcaption>
                            @if (!empty($story['title']))<b>{{ $story['title'] }}</b>@endif
                            @if (!empty($story['link']))
                                <a class="ml-btn ml-btn-gold" href="{{ $story['link'] }}">{{ $story['button_text'] ?: translate('shop_now') }}</a>
                            @endif
                        </figcaption>
                    </figure>
                @endif
            @endforeach
        </div>
    </div>
@endif
