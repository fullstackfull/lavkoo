<div class="category-create-form">
    <div class="card shadow-sm">
        <div class="card-header shadow-none">
            <h4 class="m-0">{{ translate('Add_New_Category') }}</h4>
        </div>
        <div class="card-body">
            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab lang_tab gap-3 mb-4">
                    @foreach($languages as $lang)
                    <li class="nav-item text-capitalize px-0 {{$lang == $defaultLanguage ? 'active':''}}">
                        <a class="nav-link lang-link form-dynamic-language-tab px-2 {{$lang == $defaultLanguage ? 'active':''}}"
                           href="javascript:"
                           data-lang="{{ $lang }}"
                           data-common="category-lang-tab"
                           data-target-selector=".category-lang-{{ $lang }}-tab"
                        >{{getLanguageName($lang).'('.strtoupper($lang).')'}}</a>
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
            <form action="{{ route('admin.blog.category.add') }}" method="POST" class="category-form-submit" id="blog-category-add-form">
                @csrf
                <div class="mb-4">
                    <div class="category-section">
                        @foreach($languages as $lang)
                        <div class="{{$lang != $defaultLanguage ? 'd-none':''}} category-lang-tab category-lang-{{ $lang }}-tab" data-lang="{{ $lang }}">
                            <div class="form-group">
                                <label class="form-label category-label">{{ translate('Category_Name') }} ({{strtoupper($lang)}})</label>
                                <input type="text" name="name[{{$lang}}]" class="form-control category_name" id="{{$lang}}_category_name" placeholder="{{translate('ex').':'.translate('LUX')}}">
                            </div>
                        </div>
                        <input type="hidden" name="lang[{{$lang}}]" value="{{$lang}}" id="lang-{{$lang}}">
                        @endforeach
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-3 justify-content-end">
                    <button type="reset" id="reset" class="btn btn-secondary">
                        {{ translate('Reset') }}
                    </button>
                    <button class="btn btn-primary category-form-submit-btn"
                            data-type="add"
                            data-form="#blog-category-add-form" data-route="{{ route('admin.blog.category.add') }}"
                    >
                        {{ translate('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
