@extends('layouts.admin.app')

@section('title', translate('Robots.txt'))

@section('content')
    <div class="content container-fluid">
        <div class="row g-3 align-items-center mb-3">
            <div class="{{ config('app.mode') == 'demo' ? 'col-md-8' : 'col-md-12' }}">
                <h1 class="mb-3 sm-sm-20">
                    {{ translate('SEO_Settings') }}
                </h1>

                @include('admin-views.seo-settings._inline-menu')
            </div>

            @if(config('app.mode') == 'demo')
                <div class="col-md-4">
                    <div
                        class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                        <i class="fi fi-sr-lightbulb-on text-warning"></i>
                        <span>
                            {{ translate('The_robots.txt_editor_lets_you_tell_search_engines_which_parts_of_your_website_they_should_or_should_not_crawl.') }}
                            {{ translate('Please_note') }}:
                        <span class="fw-semibold">{{ translate('This_feature_is_disabled_for_demo.') }}</span>
                    </span>
                    </div>
                </div>
            @endif
        </div>


        <form action="{{ route('admin.seo-settings.update-robot-text') }}" method="post">
            @csrf
            <div class="card mb-4">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="">
                        <h2>{{ translate('Robots.txt_Editor') }}</h2>
                        <p class="fs-12 mb-0">{{translate('control_search_engine_crawlers_access_to_specific_pages_on_a_website').'.'}}</p>
                    </div>

                    @if(file_exists(base_path('robots.txt')))
                        <a class="btn btn-primary" href="{{ url('/robots.txt') }}" target="_blank">
                            <span class="fw-semibold txt">{{translate('view_URL')}}</span>
                            <i class="fi fi-rr-up-right-from-square"></i>
                        </a>
                    @else
                        <button class="btn btn-primary disabled" data-bs-toggle="tooltip"
                                data-bs-title="{{ translate('Empty_File') }}">
                            <span class="fw-semibold txt">{{translate('view_URL')}}</span>
                            <i class="fi fi-rr-up-right-from-square"></i>
                        </button>
                    @endif
                </div>
                <div class="card-body">
                    <div
                        class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3 mb-sm-20">
                        <i class="fi fi-sr-lightbulb-on text-info"></i>
                        @if(config('app.mode') == 'demo')
                            <span>
                                {{ translate('the_robots.txt_editor_lets_you_tell_search_engines_which_parts_of_your_website_they_should_or_should_not_crawl.') }} {{ translate('please_note') }}:{{ translate('this_feature_is_disabled_for_demo.') }}
                            </span>
                        @else
                            <span>
                                {{ translate('the_robots.txt_editor_lets_you_tell_search_engines_which_parts_of_your_website_they_should_or_should_not_crawl.') }} {{ translate('please_note') }}:{{ translate('the_system_will_automatically_generate_a_robot.txt_for_your_site.') }} {{ translate('you_do_not_have_to_create_it_manually.') }} {{ translate('but_you_can_edit_or_modify_this_robots.txt.') }}
                            </span>
                        @endif
                    </div>

                    <div>
                        <textarea class="form-control" name="robot_text" rows="5"
                                  placeholder="{{ translate('Type_Content_Here') }}">{{$content}}</textarea>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end trans3 action-btn-wrapper-container">
                <div
                    class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="submit"
                            class="btn btn-primary px-3 px-sm-4 {{config('app.mode')!='demo'? '' : 'call-demo-alert'}}">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>

    </div>

@endsection

@push('script')

@endpush
