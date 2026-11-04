@if(count($bannerTypeMainBanner) > 0)
<section class="bg-transparent pt-3">
    <div class="container position-relative">
        <div class="row no-gutters position-relative rtl">
            @if ($categories->count() > 0 )
                <div class="col-xl-3 position-static d-none d-xl-block __top-slider-cate">
                    <div class="category-menu-wrap position-static">
                        <ul class="category-menu mt-0">
                            @foreach ($categories as $key=>$category)
                                <li>
                                    <a href="{{ route('category-products', ['slug' => $category['slug']]) }}">
                                        <span class="d-flex gap-10px justify-content-start align-items-center">
                                            <img class="aspect-1 rounded-circle" width="20" src="{{ getStorageImages(path: $category?->icon_full_url, type: 'category') }}" alt="{{ $category['name'] }}">
                                            <span class="line--limit-2">{{ $category->name }}</span>
                                        </span>
                                    </a>
                                    @if ($category->childes->count() > 0)
                                        <div class="mega_menu z-2">
                                            @foreach ($category->childes as $sub_category)
                                                <div class="mega_menu_inner">
                                                    <h6><a href="{{ route('category-products', ['slug' => $sub_category['slug']]) }}">{{$sub_category->name}}</a></h6>
                                                    @if ($sub_category->childes->count() >0)
                                                        @foreach ($sub_category->childes as $sub_sub_category)
                                                            <div>
                                                                <a href="{{ route('category-products', ['slug' => $sub_sub_category['slug']]) }}">
                                                                    {{ $sub_sub_category->name }}
                                                                </a>
                                                            </div>
                                                        @endforeach
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                            <li class="text-center">
                                <a href="{{route('categories')}}" class="text-primary font-weight-bold justify-content-center text-capitalize view-all-btn-text">
                                    {{translate('view_all')}}
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            @endif

            <div class="col-12 col-xl-9 __top-slider-images">
                <div class="{{Session::get('direction') === "rtl" ? 'pr-xl-2' : 'pl-xl-2'}}">
                    <div class="owl-theme owl-carousel hero-slider" data-loop="{{ count($bannerTypeMainBanner) > 1 ? 1 : 0 }}">
                        @foreach($bannerTypeMainBanner as $key=>$banner)
                            <a href="{{$banner['url']}}" class="d-block" target="_blank" data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $banner['id'] }}">
                                <img class="w-100 __slide-img __slide-img-170" alt="{{ translate('Banner') }}"
                                    src="{{ getStorageImages(path: $banner->photo_full_url, type: 'banner') }}">
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif
