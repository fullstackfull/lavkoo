
<div class="offcanvas offcanvas-end" tabindex="-1" id="brandViewOffcanvas-{{ $brand['id'] }}"
         aria-labelledby="brandViewOffcanvasLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header gap-3 justify-content-between bg-body">
            <h2 class="mb-0 flex-grow-1">
                {{ translate('Brand') }}
            </h2>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="d-flex flex-column gap-20">
                <div class="d-flex justify-content-end align-items-center flex-wrap gap-3 p-12 p-sm-20 bg-section rounded">
                    <label for="" class="form-label mb-0 flex-grow-1">
                        {{ translate('Action') }}
                        <span class="tooltip-icon"
                              data-bs-toggle="tooltip" data-bs-placement="top"
                              aria-label="{{ translate('Manage Brand status And deletion') }}"
                              data-bs-title="{{ translate('Manage Brand status And deletion') }}"
                        >
                        <i class="fi fi-sr-info"></i>
                    </span>
                    </label>
                    <button type="button" class="btn icon-btn btn-outline-danger bg-white border fs-18 delete-brand" style="--size: 40px;"  title="{{ translate('delete') }}"
                            data-product-count="{{ count($brand?->brandAllProducts) }}"
                            data-text="{{ translate('there_were_') . count($brand?->brandAllProducts) . translate('_products_under_this_brand') . '.' . translate('please_update_their_brand_from_the_below_list_before_deleting_this_one') . '.' }}"
                            id="{{ $brand['id'] }}">
                        <i class="fi fi-sr-trash"></i>
                    </button>
                    <form action="{{ route('admin.brand.status-update') }}" method="post"
                          id="brand-status-view{{ $brand['id'] }}-form" class="no-reload-form">
                        @csrf
                        <label
                            class="d-flex justify-content-between align-items-center gap-2 border rounded px-3 py-10 bg-white user-select-none">
                            <span class="fw-medium text-dark">{{ translate('status') }}</span>
                            <input type="hidden" name="id" value="{{ $brand['id'] }}">
                            <label class="switcher mx-auto" for="brand-status-view{{ $brand['id'] }}">
                                <input class="switcher_input custom-modal-plugin" type="checkbox"
                                       value="1" name="status"
                                       id="brand-status-view{{ $brand['id'] }}"
                                       {{ $brand['status'] == 1 ? 'checked' : '' }}
                                       data-modal-type="input-change-form"
                                       data-reload="true"
                                       data-modal-form="#brand-status-view{{ $brand['id'] }}-form"
                                       data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/brand-status-on.png') }}"
                                       data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/brand-status-off.png') }}"
                                       data-on-title="{{ translate('Want_to_Turn_ON') . ' ' . $brand['defaultname'] . ' ' . translate('status') }}"
                                       data-off-title="{{ translate('Want_to_Turn_OFF') . ' ' . $brand['defaultname'] . ' ' . translate('status') }}"
                                       data-on-message="<p>{{ translate('if_enabled_this_brand_will_be_available_on_the_website_and_customer_app') }}</p>"
                                       data-off-message="<p>{{ translate('if_disabled_this_brand_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                       data-on-button-text="{{ translate('turn_on') }}"
                                       data-off-button-text="{{ translate('turn_off') }}">
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </form>
                </div>

                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="d-flex gap-2 mb-20">
                        <div class="avatar-50 d-flex align-items-center rounded overflow-hidden">
                            <img class="w-100 h-100 object-fit-cover" alt=""
                                                     src="{{ getStorageImages(path: $brand->image_full_url, type: 'backend-brand') }}">
                        </div>
                        <h3 class="fw-medium mb-0 mt-1">{{ $brand['name'] }}</h3>
                    </div>
                    <table class="overflow-wrap-anywhere">
                        <tbody>
                        @if($brand['brand_all_products_count'] > 0)
                            <tr>
                                <td class="p-2">{{ translate('Total_Product') }}</td>
                                <td class="p-2">:</td>
                                <td class="p-2">{{$brand['brand_all_products_count']}}</td>
                            </tr>
                        @endif
                            @if($brand['image_alt_text'])
                            <tr>
                                <td class="p-2">{{ translate('Image_alt_text') }}</td>
                                <td class="p-2">:</td>
                                <td class="p-2">{{$brand['image_alt_text']}}</td>
                            </tr>
                            @endif
                            @if(!empty($brand['created_at']))
                                <tr>
                                    <td class="p-2">{{ translate('Created_Date') }}</td>
                                    <td class="p-2">:</td>
                                    <td class="p-2">
                                    <span class="">
                                        {{ \Carbon\Carbon::parse($brand['created_at'])->format('d M, Y | g:i A') }}
                                    </span>
                                    </td>
                                </tr>
                            @endif
                            @if(!empty($brand['updated_at']))
                            <tr>
                                <td class="p-2">{{ translate('Last_Modified_Date') }}</td>
                                <td class="p-2">:</td>
                                <td class="p-2">
                                    <span class="">
                                        {{ \Carbon\Carbon::parse($brand['updated_at'])->format('d M, Y | g:i A') }}
                                    </span>
                                </td>
                            </tr>
                            @endif
                        </tbody>
                    </table>
                </div>

                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="mb-20">
                        <h3 class="text-capitalize fw-medium mb-2">
                            {{ translate('Meta_title_and_description') }}
                        </h3>
                        <h4 class="fw-medium mb-1">{{ $brand?->seo?->title ?? translate('No_meta_title_set.') }}</h4>
                        <p class="mb-0">{{ $brand?->seo?->description ?? translate('No_meta_description_set.') }}</p>
                    </div>
                    <div>
                        <h3 class="fw-medium mb-2">{{ translate('Meta_images') }}</h3>
                        @if(!empty($brand?->seo?->image_full_url))
                            <img class="img-fluid rounded h-100px w-200 object-fit-cover"
                                 src="{{ getStorageImages(path: $brand?->seo?->image_full_url, type: 'backend-banner') }}"
                                 alt="{{ $brand?->seo?->title ?? 'Meta Image' }}">
                        @else
                            <p class="mb-0 text-muted">{{ translate('No_meta_image_uploaded.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="button" class="btn btn-secondary flex-grow-1" data-bs-dismiss="offcanvas">
                    {{ translate('Cancel') }}
                </button>
                <a class="btn btn-primary flex-grow-1 brand-edit-btn"
                   data-bs-toggle="offcanvas" href="#brandEditOffcanvas-{{$brand['id']}}">
                    <span class="fs-12">{{ translate('edit_details') }}</span>
                </a>
            </div>
        </div>
    </div>
