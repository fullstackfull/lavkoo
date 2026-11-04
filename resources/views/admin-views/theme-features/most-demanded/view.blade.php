@extends('layouts.admin.app')

@section('title', translate('most_demanded'))

@section('content')
<div class="content container-fluid">
    <div class="mb-3">
        <h2 class="h1 mb-0 text-capitalize d-flex gap-2">
            <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/most-demanded.png') }}" alt="">
            {{ translate('most_demanded') }}
        </h2>
    </div>

    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{route('admin.most-demanded.store') }}" method="post" class="text-start" enctype="multipart/form-data">
                        @csrf
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="h-100">
                                    <label for="name" class="form-label">{{ translate('products') }}</label>
                                    <select
                                        class="custom-select"
                                        name="product_id">
                                        <option value="" disabled selected>
                                            {{ translate('select_Product') }}
                                        </option>
                                        @foreach ($products as $key => $product)
                                            <option value="{{ $product->id }}">
                                                {{ $product['name']}}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="d-flex flex-column gap-30 bg-section rounded-8 p-20 w-100 h-100">
                                    <div>
                                        <label for="banner-image" class="form-label fw-semibold mb-1 d-flex align-items-center justify-content-center gap-1">
                                            {{ translate('banner') }}
                                            <span class="text-info-dark">
                                                ( {{ translate('ratio') }} {{ translate('4') }}:{{ translate('1') }} )
                                            </span>
                                        </label>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="image" id="banner-image" class="upload-file__input single_file_input"
                                            accept=".jpg, .png, .jpeg, .gif">
                                        <label
                                            class="upload-file__wrapper ratio-4-1">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg')}}" alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">{{ translate('Click to upload') }}</span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="">
                                        </label>
                                        <div class="overlay">
                                            <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                    <i class="fi fi-sr-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                    <i class="fi fi-rr-camera"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                            <button type="reset" id="reset" class="btn btn-secondary px-4">{{ translate('reset') }}</button>
                            <button type="submit" class="btn btn-primary px-4">{{ translate('submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body d-flex flex-column gap-20">
            <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                <h3 class="mb-0">
                    {{ translate('most_demanded_table') }}
                    <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $mostDemandedProducts->total() }}</span>
                </h3>
                <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                    <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                        <form action="{{route('admin.most-demanded.index') }}" method="GET">
                            <div class="input-group">
                                <input id="datatableSearch_" type="search" name="searchValue"
                                               class="form-control" value="{{ requestString('searchValue') }}"
                                               placeholder="{{ translate('search_by_product_name') }}"
                                               aria-label="Search orders">
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @if(count($mostDemandedProducts)>0)
            <div class="k-table-wrap">
                <table id="columnSearchDatatable"
                    class="k-table">
                    <thead>
                    <tr>
                        <th class="pl-xl-5">{{ translate('SL') }}</th>
                        <th>{{ translate('banner') }}</th>
                        <th>{{ translate('product') }}</th>
                        <th class="text-center">{{ translate('published') }}</th>
                        <th class="text-center">{{ translate('action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($mostDemandedProducts as $key=>$mostDemanded)
                        <tr id="data-{{ $mostDemanded->id}}">
                            <td class="pl-xl-5">{{ $mostDemandedProducts->firstItem()+ $key}}</td>
                            <td>
                                <img class="ratio-4:1" width="80" alt=""
                                     src="{{ getStorageImages(path:$mostDemanded->banner_full_url,type: 'backend-banner')}}">
                            </td>
                            <td>
                                @if(isset($mostDemanded->product->name))
                                    {{ $mostDemanded->product->name }}
                                @else
                                    {{ translate('no_product_found') }}
                                @endif
                            </td>
                            <td>
                                    <div class="d-flex justify-content-center align-items-center">
                                        @if(isset($mostDemanded->product->status ) && $mostDemanded->product->status == 1)
                                    <form action="{{route('admin.most-demanded.status-update') }}" method="post" id="most-demanded{{ $mostDemanded['id']}}-form"
                                          class="most-demanded-status-form">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $mostDemanded['id']}}">
                                        <label class="switcher mx-auto" for="most-demanded{{ $mostDemanded['id']}}">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="status"
                                                id="most-demanded{{ $mostDemanded['id'] }}"
                                                {{ $mostDemanded['status'] == 1 ? 'checked' : '' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#most-demanded{{ $mostDemanded['id']}}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/most-demanded-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/most-demanded-off.png') }}"
                                                data-on-title="{{ translate('Want_to_Turn_ON_Most_Demanded_Product_Status') }}"
                                                data-off-title="{{ translate('Want_to_Turn_OFF_Most_Demanded_Product_Status') }}"
                                                data-on-message="<p>{{ translate('if_enabled_this_most_demanded_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                data-off-message="<p>{{ translate('if_disabled_this_most_demanded_product_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                    @else
                                    <label class="switcher">
                                        <input type="checkbox" class="switcher_input status" name="status" id="{{ $mostDemanded->id}}" disabled>
                                        <span class="switcher_control"></span>
                                    </label>
                                    @endif
                                    </div>
                            </td>
                            <td>
                                <div class="d-flex gap-10 justify-content-center">
                                    <a class="btn btn-outline-primary icon-btn cursor-pointer edit"
                                    title="{{ translate('edit') }}"
                                    href="{{route('admin.most-demanded.edit',[$mostDemanded['id']]) }}">
                                        <i class="fi fi-rr-pencil"></i>
                                    </a>
                                    <a class="btn btn-outline-danger icon-btn cursor-pointer most-demanded-product-delete-button"
                                    title="{{ translate('delete') }}"
                                       data-warning-text ="{{ translate('are_you_sure_delete_this_most_demanded_product') }}"
                                       data-text ="{{ translate('you_will_not_be_able_to_revert_this') }}"
                                    id="{{ $mostDemanded['id'] }}">
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
            <div class="px-4 d-flex justify-content-lg-end">
                {{ $mostDemandedProducts->links() }}
            </div>
        </div>
        @endif
        @if(count($mostDemandedProducts)==0)
            @include('layouts.admin.partials._empty-state',['text'=>'no_data_found'],['image'=>'default'])
        @endif
        </div>
    </div>
</div>

<span id="route-admin-most-demanded-delete" data-url="{{ route('admin.most-demanded.delete') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/promotion/banner.js') }}"></script>
@endpush
