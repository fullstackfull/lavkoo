{{-- The newest posts from the Blog module; nothing renders when it is off. --}}

    <div class="ml-sec-head ml-reveal">
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            <h2>{{ $s['title'] ?: translate('from_the_blog') }}</h2>
        </div>
        @if (($s['view_all'] ?? true) && $viewAllUrl)
            <a class="ml-viewall" href="{{ $viewAllUrl }}">{{ translate('view_all') }}</a>
        @endif
    </div>
    @php $postStyle = $s['style'] ?? 'cards'; @endphp
    {{-- Cards for a magazine look, list when the headline is the thing and
         the images are weak, featured when the newest post deserves the
         room and the rest are a reading list beside it. --}}
    <div class="{{ $postStyle === 'cards' ? 'ml-grid' : 'ml-posts--' . $postStyle }}">
        @foreach ($posts as $post)
            @php
                $postUrl = \Illuminate\Support\Facades\Route::has('frontend.blog.details') && $post->slug
                    ? route('frontend.blog.details', ['slug' => $post->slug])
                    : url('/');
            @endphp
            <a class="ml-post ml-reveal {{ $postStyle === 'featured' && $loop->first ? 'is-lead' : '' }}"
               data-delay="{{ $loop->index % 6 }}" href="{{ $postUrl }}">
                <span class="ml-post__thumb">
                    <img src="{{ getStorageImages(path: $post->thumbnail_full_url, type: 'blog') }}"
                         alt="{{ $post->title }}" loading="lazy">
                </span>
                <span class="ml-post__body">
                    <small>{{ \Carbon\Carbon::parse($post->publish_date)->translatedFormat('d M Y') }}</small>
                    <b>{{ Str::limit($post->title, 70) }}</b>
                </span>
            </a>
        @endforeach
    </div>
