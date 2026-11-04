{{--
    A grid of banners laid out by each banner's own layout:
      full   — its own row
      half   — half a row, pairing up with the next half beside it
      slider — pooled with the other sliders into one rotating full-width slot
    Used by the home promo grid and by a category's section banners, so both
    place banners the same way. Expects: $banners (collection), $fallbackUrl.
--}}
@php($gridBanners = ($banners ?? collect())->filter())

@if ($gridBanners->count() > 0)
    @php($sliderBanners = $gridBanners->where('layout', 'slider')->values())
    @php($tileBanners = $gridBanners->where('layout', '!=', 'slider')->values())

    <div class="banner-grid" dir="{{ session('direction') }}">
        @foreach ($tileBanners as $banner)
            <a class="banner-grid__item {{ $banner['layout'] == 'half' ? 'banner-grid__item--half' : '' }}"
               href="{{ $banner['url'] ?: ($fallbackUrl ?? 'javascript:') }}" data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $banner['id'] }}">
                <img loading="lazy"
                     src="{{ getStorageImages(path: $banner->photo_full_url, type: 'banner') }}"
                     alt="{{ $banner['title'] ?: translate('banner') }}">
            </a>
        @endforeach

        @if ($sliderBanners->count() > 0)
            <div class="banner-grid__item banner-grid__slider">
                <div class="owl-carousel owl-theme banner-grid-slider" data-slide-items="1">
                    @foreach ($sliderBanners as $banner)
                        <a class="banner-grid__slide" href="{{ $banner['url'] ?: ($fallbackUrl ?? 'javascript:') }}" data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $banner['id'] }}">
                            <img loading="lazy"
                                 src="{{ getStorageImages(path: $banner->photo_full_url, type: 'banner') }}"
                                 alt="{{ $banner['title'] ?: translate('banner') }}">
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
