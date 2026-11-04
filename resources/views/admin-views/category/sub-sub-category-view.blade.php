@extends('layouts.admin.app')

@section('title', translate('sub_Sub_Category'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/brand-setup.png') }}" alt="">
                {{ translate('sub_Sub_Category_Setup') }}
            </h2>
        </div>

        <div class="row mt-20" id="cate-table">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-20">
                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                            <h3 class="mb-0">
                                {{ translate('Sub_Sub_Category_List') }}
                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $categories->total() }}</span>
                            </h3>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <form action="{{ url()->current() }}" method="GET" class="flex-grow-1">
                                    <div class="input-group min-w-300">
                                        <input id="" type="search" name="searchValue" class="form-control pe-2"
                                               placeholder="{{ translate('search_by_sub_sub_category_name') }}"
                                               value="{{ requestString('searchValue') }}">
                                        <div class="input-group-append search-submit">
                                            <button type="submit">
                                                <i class="fi fi-rr-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                <div class="dropdown">
                                    <a type="button" class="btn btn-outline-primary" href="{{ route('admin.sub-sub-category.export',['searchValue'=>request('searchValue')]) }}">
                                        <i class="fi fi-sr-inbox-in"></i>
                                        <span class="fs-12">{{ translate('export') }}</span>
                                    </a>
                                </div>
                                <div>
                                    <button class="btn btn-primary" title="{{ translate('Add') }}"
                                            data-bs-toggle="offcanvas" href="#categoryAddOffcanvas"
                                    > + {{ translate('Add_Sub_Sub_Category') }}</button>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{ translate('SL') }}</th>
                                    <th>{{ translate('icon') }}</th>
                                    <th>{{ translate('sub_sub_category_name') }}</th>
                                    <th>{{ translate('sub_category_name') }}</th>
                                    <th>{{ translate('category_name') }}</th>
                                    <th class="text-center">{{ translate('priority') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($categories as $key=>$category)
                                    <tr>
                                        <td>{{ $categories->firstItem() + $key }}</td>
                                        {{-- The uploaded icon, or a letter chip: a row of identical grey placeholders
                                             reads as "the icons are broken" rather than "this one has none". --}}
                                        <td>
                                            @php($subIcon = category_icon_url($category))
                                            @if ($subIcon)
                                                <img class="rounded" width="38" height="38" loading="lazy"
                                                     src="{{ $subIcon }}" alt="{{ $category['defaultname'] }}">
                                            @else
                                                <span class="category-icon-placeholder" title="{{ translate('no_icon_uploaded_yet') }}">
                                                    {{ mb_substr(trim((string) $category['defaultname']), 0, 1) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <h6 class="fs-14">{{ $category['defaultname'] }}</h6>
                                            <span class="fs-12">{{ translate('ID') }}  #{{ $category['id'] }}</span>
                                        </td>
                                        <td>{{$category?->parent?->defaultname ?? translate('sub_category_not_found') }}</td>
                                        <td>{{$category?->parent?->parent?->defaultname ??translate('sub_category_not_found') }}</td>
                                        <td class="text-center">{{ $category['priority']}}</td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-2">
                                                <a class="btn btn-outline-info icon-btn edit" title="{{ translate('Edit') }}"
                                                   data-bs-toggle="offcanvas" href="#categoryEditOffcanvas-{{ $category['id'] }}">
                                                    <i class="fi fi-sr-pencil"></i>
                                                </a>
                                                <a class="btn btn-outline-danger icon-btn category-delete-button"
                                                   title="{{ translate('delete') }}"
                                                   id="{{ $category['id']}}">
                                                    <i class="fi fi-rr-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="table-responsive mt-4">
                            <div class="d-flex justify-content-lg-end">
                                {{ $categories->links() }}
                            </div>
                        </div>
                        @if(count($categories) == 0)
                            @include('layouts.admin.partials._empty-state',['text'=>'no_sub_sub_category_found'],['image'=>'default'])
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("admin-views.category.offcanvas._category-add", ['categoryType' => 'sub_sub_category', '' => $parentCategories])
    @foreach($categoriesWithTrans as $key => $category)
        @include("admin-views.category.offcanvas._category-edit", ['category' => $category])
    @endforeach

    <span id="route-admin-category-delete" data-url="{{ route('admin.sub-sub-category.delete') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
