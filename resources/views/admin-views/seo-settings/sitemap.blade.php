@extends('layouts.admin.app')

@section('title', translate('Sitemap'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/seo-settings.svg') }}" alt="">
                {{ translate('SEO_Settings') }}
            </h2>
        </div>
        @include('admin-views.seo-settings._inline-menu')

        <div class="card shadow-none">
            <div class="card-header">
                <div class="w-100">
                    <h3 class="mb-0">{{translate('Site_Map')}}</h3>
                    <p class="fs-12 mb-0">{{ translate("Organized_for_navigation_and_search_engine_optimization.") }}</p>
                </div>
            </div>
            <div class="card-body">
                <div
                    class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                    <span>
                        {{ translate('a_sitemap_is_an_xml_file_that_contains_all_the_web_pages_of_a_website.') }}
                        {{ translate('here_we_list_and_organize_all_the_default_pages_in_a_hierarchical_structure_of_your_website_through_xml_sitemap.') }}
                        {{ translate('it_allows_search_engines_to_find_and_display_your_products_and_services_in_search_results.')}}
                    </span>
                </div>
                <div class="text-center py-3">
                    <h4 class="fs-16 mb-3">
                        {{ translate('Download_Generate_Sitemap') }}
                    </h4>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <button id="{{config('app.mode')!='demo'? 'generateAndDownloadSitemap' : '' }}"
                                data-route="{{ route('admin.seo-settings.sitemap-generate-download') }}"
                                class="btn btn-primary px-5 d-flex gap-2 align-items-center {{config('app.mode')!='demo'? '' : 'call-demo-alert' }}">
                            <span class="spinner-border extra-small-spinner-border d--none" role="status"
                                  id="{{config('app.mode')!='demo'? 'generateAndDownloadSitemapSpinner' : '' }}">
                                <span class="sr-only"></span>
                            </span>
                            <span>{{ translate('Generate_&_Download') }}</span>
                        </button>
                        <a href="{{ config('app.mode') != 'demo' ? route('admin.seo-settings.sitemap-generate-upload') : 'javascript:' }}"
                           class="btn btn-outline-primary px-5 {{ getDemoModeFormButton(type: 'class') }}">
                            {{ translate('Generate_&_Upload_to_Server') }}
                        </a>
                        <button class="btn btn-outline-primary px-5" data-bs-toggle="modal"
                                data-bs-target="#sitemap-upload-modal" type="button">
                            {{ translate('Upload_Sitemap') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body p-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead class="text-uppercase">
                        <tr>
                            <th class="w-95">
                                {{ translate('SL') }}
                            </th>
                            <th class="w-45">{{ translate('name') }}</th>
                            <th class="w-200 text-center">{{ translate('file_Size') }}</th>
                            <th class="w-200 text-center">{{ translate('date') }}</th>
                            <th class="text-center">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($siteMapList as $siteMapIndex => $siteMap)
                            <tr>
                                <td>
                                    <span class="fw-semibold text-dark">
                                        {{ $siteMapList->firstItem() + $siteMapIndex }}
                                    </span>
                                </td>
                                <td>
                                    <span>{{ $siteMap['name'] }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-center">
                                        {{ $siteMap['size'] }}
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <span>{{ $siteMap['created_at']->diffForHumans() }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-2">
                                        <a class="btn btn-outline-success btn-outline-success-dark icon-btn {{ getDemoModeFormButton(type: 'class') }}"
                                           href="{{config('app.mode')!='demo'? route('admin.seo-settings.sitemap-download', ['path' => base64_encode($siteMap['name'])]) : 'javascript:'}}">
                                            <i class="fi fi-sr-down-to-line"></i>
                                        </a>

                                        <a class="btn btn-outline-danger icon-btn {{ getDemoModeFormButton(type: 'class') }}"
                                           href="{{ config('app.mode') != 'demo'? route('admin.seo-settings.sitemap-delete', ['path' => base64_encode($siteMap['name'])]) : 'javascript:'}}">
                                            <i class="fi fi-rr-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="page-area px-4 pt-4">
                    <div class="d-flex align-items-center justify-content-end">
                        <div>
                            {{ $siteMapList->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("admin-views.seo-settings._sitemap-upload-modal")
@endsection

@push('script')
    <script
        src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/business-settings.js') }}"></script>
@endpush
