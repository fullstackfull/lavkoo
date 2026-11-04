@extends('layouts.front-end.app')

@section('title', translate('all_Categories'))

@push('css_or_js')
    <meta property="og:image" content="{{$web_config['web_logo']['path']}}"/>
    <meta property="og:title" content="Categories of {{$web_config['company_name']}} "/>
    <meta property="og:url" content="{{env('APP_URL')}}">
    <meta property="og:description" content="{{ $web_config['meta_description'] }}">
    <meta property="twitter:card" content="{{$web_config['web_logo']['path']}}"/>
    <meta property="twitter:title" content="Categories of {{$web_config['company_name']}}"/>
    <meta property="twitter:url" content="{{env('APP_URL')}}">
    <meta property="twitter:description" content="{{ $web_config['meta_description'] }}">
@endpush

@section('content')
    <div class="container pb-3 mb-2 mb-md-4 rtl __inline-52 text-align-direction">

        <div class="bg-primary-light rounded-10 my-4 p-3 p-sm-4" data-bg-img="{{ theme_asset(path: 'public/assets/front-end/img/media/bg.png') }}">
             <div class="row align-items-center g-3">
                <div class="col-xl-8 col-lg-7 col-md-6">
                    <div class="d-flex flex-column gap-1 text-primary">
                        <h4 class="mb-0 text-start fw-bold text-primary text-uppercase">
                            {{ translate('category') }}
                        </h4>
                        <p class="fs-14 fw-semibold mb-0">
                            {{ translate('Find_your_favorite_categories') }}
                        </p>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-5 col-md-6">
                    <form action="{{ route('categories') }}" method="GET">
                       <div class="d-flex align-items-center gap-2 position-relative search-rounded-10" style="--radius: 10px;">
                           <input class="form-control appended-form-control pe-5rem search-page-button-input" type="search" autocomplete="off"
                               placeholder="{{ translate('Search_Categories') }}" name="search" value="{{ request('search') }}">
                           <button class="input-group-append-overlay search_button d-md-block search-page-button" data-name="name">
                               <span class="input-group-text">
                                   <i class="czi-search text-absolute-white"></i>
                               </span>
                           </button>
                       </div>
                    </form>
                </div>
            </div>
        </div>

        @if(count($categories) > 0)
            <div class="brand_div-wrap">
                @foreach($categories as $categoryKey => $category)
                    <a href="{{ route('category-products', ['slug' => $category['slug']]) }}" class="brand_div">
                        <img src="{{ getStorageImages(path: $category->icon_full_url, type: 'category') }}" alt="{{ $category['name'] }}">
                        <div>{{ $category['name'] }}</div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="d-flex justify-content-center align-items-center pt-3">
                <div class="d-flex flex-column justify-content-center align-items-center gap-3">
                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/img/empty-icons/empty-category.svg') }}"
                         alt="{{ translate('category') }}" class="img-fluid" width="100">
                    <h5 class="text-muted fs-14 font-semi-bold text-center">{{ translate('There_is_no_category') }}</h5>
                </div>
            </div>
        @endif

    </div>
@endsection

@push('script')
    <script src="{{ asset('public/assets/front-end/js/categories.js') }}"></script>
@endpush
