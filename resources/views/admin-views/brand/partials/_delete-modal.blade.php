<div class="modal fade" id="deleteModal-{{$brand['id']}}" tabindex="-1" aria-labelledby="deleteModal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none" style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross"></i>
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png') }}" width="80" class="mb-20" id="" alt="">
                    <h2 class="modal-title mb-3" id="">{{ translate('Are_you_sure_to_delete_this_brand') }}?</h2>
                    <div class="text-center" id="">{{ translate('If once you delete this Brand, you will lost this Brand data permanently.') }} </div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1" data-bs-dismiss="modal">
                        {{ translate('No') }}
                    </button>
                    <button type="button" class="btn btn-danger max-w-120 flex-grow-1 delete-brand"
                            title="{{ translate('delete') }}"
                            data-product-count = "{{ count($brand?->brandAllProducts) }}"
                            data-text="{{ translate('there_were_') . count($brand?->brandAllProducts) . translate('_products_under_this_brand') . '.' . translate('please_update_their_brand_from_the_below_list_before_deleting_this_one') . '.' }}"
                            id="{{ $brand['id'] }}">
                        {{ translate('Yes, Delete') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
