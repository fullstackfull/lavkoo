{{-- Three ways to present the same brands, chosen in the builder:
     marquee (continuous logo strip), grid (bordered logo cards), or
     story (large gradient cards, one per brand). Every card links to the
     brand's own landing page, which carries its banner and category chips. --}}

@php
    $brands = $__resolver->brands((int) ($s['limit'] ?? 12));
    $brandStyle = $s['style'] ?? 'marquee';
    $brandUrl = fn ($brand) => \Illuminate\Support\Facades\Route::has('brand-products') && $brand->slug
        ? route('brand-products', ['slug' => $brand->slug])
        : route('products', ['brand_id' => $brand->id]);
@endphp
@if ($brands->isNotEmpty())
    <div class="ml-sec-head ml-reveal">
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        </div>
        @if (\Illuminate\Support\Facades\Route::has('brands'))
            <a class="ml-viewall" href="{{ route('brands') }}">{{ translate('all_brands') }}</a>
        @endif
    </div>

    @if ($brandStyle === 'story')
        <div class="ml-story">
            @foreach ($brands->take(6) as $brand)
                <a class="ml-storycard ml-reveal" data-delay="{{ $loop->index % 6 }}" href="{{ $brandUrl($brand) }}">
                    <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                    <h3>{{ $brand->name }}</h3>
                    <span>{{ translate('shop_the_brand') }} &#8592;</span>
                </a>
            @endforeach
        </div>
    @elseif ($brandStyle === 'grid')
        <div class="ml-brandgrid ml-reveal">
            @foreach ($brands as $brand)
                <a class="ml-brandcard" href="{{ $brandUrl($brand) }}">
                    <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                    {{ $brand->name }}
                </a>
            @endforeach
        </div>
    @else
        <div class="ml-marquee ml-reveal">
            <div class="ml-marquee__track">
                @foreach ($brands->concat($brands) as $brand)
                    <a href="{{ $brandUrl($brand) }}" class="ml-brand" aria-hidden="{{ $loop->index >= $brands->count() ? 'true' : 'false' }}">
                        <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endif
