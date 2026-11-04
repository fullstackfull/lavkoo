@extends('layouts.admin.app')

@section('title', translate('Robots_meta_content'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/seo-settings.svg') }}" alt="">
                {{ translate('SEO_Settings') }}
            </h2>
        </div>
        @include('admin-views.seo-settings._inline-menu')

        <div class="card mb-3 shadow-none">
            <div class="card-header flex-wrap gap-2 py-5">
                <div class="text-center w-100">
                    <h3 class="mb-2">
                        {{ $defaultPageData ? translate('update_Default_Meta') : translate('Set_Default_Meta') }}
                    </h3>
                    <p class="mb-4 fs-12">{{ translate("if_you_do_not_have_any_meta_content_in_any_page_it_will_automatically_use_as_meta_content_from_this_section.")}}</p>
                    <a class="btn btn-outline-success {{ $defaultPageData ? 'edit-content-btn' : 'add-content-btn' }}"
                    href="{{ route('admin.seo-settings.robots-meta-content.page-content-view', ['page_name' => 'default']) }}">
                        <i class="fi fi-rr-plus"></i>
                        <span class="txt">
                            {{ $defaultPageData ? translate("edit_Content") : translate("Add_Content") }}
                        </span>
                    </a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="">
                    <h4 class="title">{{ translate('default_Pages_Robots_Meta_Content_Settings') }}</h4>
                    <p class="m-0">
                        {{ translate("optimize_your_Websites_performance_indexing_status_and_search_visibility.") }}
                    </p>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#page-add-modal" type="button">
                        <img src="{{ dynamicAsset('public/assets/back-end/img/add-btn.png')}}" alt="">
                        <span class="txt">{{ translate('Add_Page') }}</span>
                        <span data-bs-toggle="tooltip" title="{{ translate('fetch_static_page_to_edit_the_meta_content') }}">
                            <img src="{{ dynamicAsset('public/assets/back-end/img/query.png')}}" alt="">
                        </span>
                    </button>
                </div>
            </div>
            <div class="card-body px-0">
                @if(count($pageListData) > 0)
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead class="text-capitalize">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('Pages') }}</th>
                            <th>{{ translate('URL') }}</th>
                            <th class="text-center">{{ translate('Action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($pageListData as $key => $pageListSingle)
                            <tr>
                                <td>
                                    {{ $pageListData->firstItem() + $key }}
                                </td>
                                <td>
                                    <span class="font-weight-semibold text-title">
                                        {{ translate($pageListSingle['page_title']) }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ $pageListSingle['page_url'] ? $pageListSingle['page_url'] : 'javascript:' }}" class="text-primary text-underline" target="_blank">
                                        {{ $pageListSingle['page_url'] }}
                                    </a>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-center align-items-center gap-2">
                                        <a href="{{ route('admin.seo-settings.robots-meta-content.page-content-view', ['page_name' => $pageListSingle['page_name']]) }}"
                                           class="btn icon-btn w-120 {{ $pageListSingle['meta_title'] ? 'btn-outline-primary' : 'btn-outline-success' }}">
                                           {{ $pageListSingle['meta_title'] ? translate('edit_Content') : translate('add_Content') }}
                                        </a>
                                        <a class="btn btn-outline-danger icon-btn {{ getDemoModeFormButton(type: 'class') }}"
                                           href="{{ config('app.mode') != 'demo' ? route('admin.seo-settings.robots-meta-content.delete-page', ['id' => $pageListSingle['id']]) : 'javascript:' }}">
                                            <i class="fi fi-rr-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                    <div class="page-area mt-4">
                        <div class="d-flex align-items-center justify-content-end">
                            <div>
                                {{ $pageListData->links() }}
                            </div>
                        </div>
                    </div>
                @else
                    @include('layouts.admin.partials._empty-state', ['text'=>'no_data_found'], ['image'=>'default'])
                @endif
            </div>
        </div>

        <div class="modal fade" tabindex="-1" role="dialog" id="page-add-modal">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title w-100 text-center">{{ translate('Add_Page') }}</h2>
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                        </button>
                    </div>
                    <div class="modal-body">
                        <form action="{{ route('admin.seo-settings.robots-meta-content.add-page') }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">{{translate('Page_Name')}}</label>
                                <div class="">
                                    <select class="custom-select" name="page_name" required id="robotsMetaContentPageSelect">
                                        @foreach($pageList as $pageRoute => $pageInfo)
                                            <option value="{{ $pageRoute }}">{{ translate($pageInfo['title']) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{translate('Page_URL')}}</label>
                                <input type="url" class="form-control" name="page_url"
                                       id="robotsMetaContentPageUrl" placeholder="{{ translate('Enter_URL') }}"
                                       value="{{ $pageList[array_key_first($pageList)]['route'] }}"
                                       readonly>
                            </div>
                            <div class="mb-3 d-flex justify-content-end">
                                <button type="{{config('app.mode')!='demo'? 'submit' : 'button' }}" class="btn btn-primary {{config('app.mode')!='demo'? '' : 'call-demo-alert' }}">
                                    {{ translate('save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="btn--container"></div>
                </div>
            </div>
        </div>
    </div>

    <span id="robotsMetaContentPageURoutes"
    @foreach($pageList as $pageRoute => $pageInfo)
        data-{{ strtolower($pageRoute) }}="{{ $pageInfo['route'] ?? '' }}"
    @endforeach
    ></span>

@endsection

@push('script')

@endpush
