@extends('layouts.admin.app')

@section('title', translate('Blog'))

@push('css_or_js')

@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/blog-logo.png') }}" alt="">
                {{ translate('Blog') }}
            </h2>
        </div>

        @include('blog::admin-views.blog.partials._blog-tab-menu')

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-center">
                    <div class="col-md-8 col-xl-9">
                        <h3>{{ translate('Blog_Section') }}</h3>
                        <p class="m-0">
                            {{ translate('enabling_this_option_will_make_the_blog_section_visible_on_the_website_for_viewers') }}
                        </p>
                    </div>
                    <div class="col-md-4 col-xl-3">
                        <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
                            <h5 class="mb-0 font-weight-normal">{{ translate('Activate_Blog') }}</h5>
                            <form action="{{ route('admin.blog.status-update') }}" method="post" class="blog-status-form"
                                  id="blog-custom-status-form" data-id="blog-custom-status-form">
                                @csrf
                                <label class="switcher" for="blog-update-status">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="blog-update-status"
                                        {{ getWebConfig(name: 'blog_feature_active_status') == 1 ? 'checked' : '' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#blog-custom-status-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/blog-status-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/blog-status-off.png') }}"
                                        data-on-title="{{ translate('are_you_sure_to_turn_on_the_blog_status') }}"
                                        data-off-title="{{ translate('are_you_sure_to_turn_off_the_blog_status') }}"
                                        data-on-message="<p>{{ translate('once_you_turn_on_this_blog_it_will_be_visible_to_the_blog_list_for_users.') }}</p>"
                                        data-off-message="<p>{{ translate('when_you_turn_off_this_blog_it_will_not_be_visible_to_the_blog_list_for_users') }}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('blog::admin-views.blog.partials._blog-intro-section')

        <div class="card mb-3">
            <div class="card-body">
                @include('blog::admin-views.blog.partials._blog-filter-section')

                <div class="d-flex align-items-stretch flex-wrap justify-content-between gap-3 mb-20">
                    <h4 class="m-0 flex-grow-1 d-flex gap-2 align-items-center flex-wrap">{{ translate('Blog_List') }}
                        @if(count($blogs) > 0)
                            <span class="badge text-dark bg-body-secondary fw-semibold rounded-45">
                                {{ $blogs->total() }}
                            </span>
                        @endif
                    </h4>
                    <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="form-group">
                                <div class="input-group">
                                    <input id="datatableSearch_" type="search" class="form-control" name="searchValue" value="{{ request('searchValue') }}" placeholder="{{ translate('Search_by_title') }}...">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <a href="{{ route('admin.blog.add') }}" class="btn btn-primary"> + {{ translate('Create_Blog') }}</a>
                </div>

                @if(count($blogs) > 0)
                    @include('blog::admin-views.blog.partials._blog-list-section')
                @else
                    <div class="p-4 bg-chat rounded text-center">
                        <div class="py-5">
                            <img src="{{ dynamicAsset('public/assets/back-end/img/empty-blog.png') }}" width="64"
                                 alt="">
                            <div class="mx-auto my-3 max-w-353px">
                                {{ translate('currently_no_blog_available_in_this_state') }}
                            </div>
                            @if(!request()->has('searchValue'))
                                <a href="{{ route('admin.blog.add') }}" class="text-primary text-underline">
                                    + {{ translate('create_blog') }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
@push('script')
    <script>
        $('.blog-status-form, .single-blog-status-form').on('submit', function (event) {
            event.preventDefault();
            $.ajax({
                url: $(this).attr('action'),
                method: 'POST',
                data: $(this).serialize(),
                success: function (response) {
                    toastMagic.success(response.message);
                },
            });
        });
    </script>
@endpush
