@extends('layouts.admin.app')
@section('title', translate('deal_Update'))
@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/deal_of_the_day.png') }}" alt="">
                {{ translate('update_Deal_of_The_Day') }}
            </h2>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form action="{{route('admin.deal.day-update',[$deal['id']]) }}"
                                class="text-start  form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate"
                                method="post">
                            @csrf
                            @php($language = getWebConfig(name:'pnc_language'))
                            @php($defaultLanguage = 'en')
                            @php($defaultLanguage = $language[0])
                            <div class="position-relative nav--tab-wrapper mb-4">
                                <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                    @foreach($language as $lang)
                                        <li class="nav-item px-0" role="presentation">
                                            <a class="nav-link px-2 {{ $lang == $defaultLanguage? 'active':'' }}" id="{{ $lang }}-link" data-bs-toggle="pill" href="#{{ $lang }}-form" role="tab" aria-selected="true">
                                                {{ getLanguageName($lang).'('.strtoupper($lang).')' }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="nav--tab__prev">
                                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-left"></i>
                                    </button>
                                </div>
                                <div class="nav--tab__next">
                                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-right"></i>
                                    </button>
                                </div>

                            </div>
                            <div class="form-group">
                                <div class="tab-content" id="pills-tabContent">
                                    @foreach($language as $lang)
                                        <?php
                                            if (count($deal['translations'])) {
                                                $translate = [];
                                                foreach ($deal['translations'] as $t) {
                                                    if ($t->locale == $lang && $t->key == "title") {
                                                        $translate[$lang]['title'] = $t->value;
                                                    }
                                                }
                                            }
                                        ?>
                                        <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active':'' }}" id="{{ $lang }}-form" role="tabpanel" aria-labelledby="{{ $lang }}-form-tab">
                                            <div class="col-md-12">
                                                <label for="name" class="form-label">{{ translate('title') }}
                                                    ({{strtoupper($lang) }})
                                                    @if($lang == $defaultLanguage)<span class="text-danger">*</span>@endif
                                                </label>
                                                <input type="text" name="title[]"
                                                       value="{{ $lang==$defaultLanguage?$deal['title']:($translate[$lang]['title']??'') }}"
                                                       class="form-control" id="title"
                                                       data-required-msg="{{ translate('title_is_required') }}"
                                                       required
                                                       placeholder="{{ translate('ex') }}: {{ translate('Daily Spotlight Deal') }}">
                                            </div>
                                        </div>
                                        <input type="hidden" name="lang[]" value="{{ $lang }}" id="lang">
                                    @endforeach
                                </div>
                                <div class="row">
                                    <div class="col-md-12 mt-3">
                                        <label for="name" class="form-label">{{ translate('products') }} <span class="text-danger">*</span></label>
                                        <input type="text" class="product_id" name="product_id"
                                               value="{{ $deal['product_id'] }}" hidden>
                                        <div class="dropdown select-product-search w-100">
                                            <button class="form-select bg-transparent shadow-none text-start dropdown-toggle line-1 word-break select-product-button"
                                                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" type="button">
                                                    {{ isset($deal->product) ? $deal->product->name : translate('product_not_found') }}
                                            </button>
                                            <div class="dropdown-menu w-100 px-2">
                                                <div class="search-form mb-3">
                                                    <button type="button" class="btn"><i class="fi fi-rr-search"></i>
                                                    </button>
                                                    <input type="text"
                                                           class="js-form-search form-control search-bar-input search-all-type-product"
                                                           placeholder="{{ translate('search menu').'...' }}">
                                                </div>
                                                <div
                                                    class="d-flex flex-column gap-3 max-h-200 overflow-y-auto overflow-x-hidden search-result-box">
                                                    @include('admin-views.partials._search-product',['products'=>$products])
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-3 flex-wrap">
                                <button type="reset" id="reset"
                                        class="btn btn-secondary px-5 reset-button">{{ translate('reset') }}</button>
                                <button type="submit" class="btn btn-primary px-5">{{ translate('update') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/search-product.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deal.js') }}"></script>
@endpush

