<div class="modal fade" id="select-brand-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-4 px-sm-5 pt-0 pb-sm-5">
                <div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
                    <div
                        class="toggle-modal-img-box d-flex flex-column justify-content-center align-items-center mb-2 position-relative">
                        <img src="{{ dynamicAsset('public/assets/new/back-end/img/modal/delete.png') }}" alt=""
                             width="90" />
                    </div>
                    <h3 class="mb-1 fs-18">{{ translate('Are you sure to delete this Brand?') }}</h3>
                    <p class="modal-title mb-20 fw-normal brand-title-message"></p>
                </div>
                <form action="{{ route('admin.brand.delete') }}" method="post"
                      class="product-brand-update-form-submit">
                    @csrf
                    <input name="id" hidden value="">
                    <div class="gap-2 mb-30">
                        <label class="form-label" for="exampleFormControlSelect1">{{ translate('Select_Brand') }}
                            <span class="text-danger">*</span>
                        </label>
                        <select class="custom-select brand-option" name="brand_id"
                                data-placeholder="Select from dropdown" required>
                            @foreach($brands as $brand)
                                <option value="{{$brand['id']}}">{{ $brand['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-secondary min-w-120"
                                data-bs-dismiss="modal">{{ translate('No') }}</button>
                        <button type="submit" class="btn btn-danger min-w-120">{{ translate('Shift & Delete') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
