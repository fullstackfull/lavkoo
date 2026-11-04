@if(count($generateCombination) > 0)
    <div class="k-table-wrap mt-3">
        <table class="k-table">
            <thead>
            <tr>
                <th class="text-start">{{ translate('SL') }}</th>
                <th class="text-start">{{ translate('Attribute_Variation') }}</th>
                <th class="text-start">
                    {{ translate('Variation_Wise_Price') }}
                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                </th>
                <th class="text-start">{{ translate('SKU') }}</th>
                @if($digitalProductType == 'ready_product')
                    <th>
                        <div class="d-flex justify-content-start align-items-center gap-1">
                            <span>{{ translate('Upload_File') }}</span>
                            <span class="input-label-secondary cursor-pointer mb-1"
                                data-bs-toggle="tooltip" data-bs-placement="top"
                                title="{{ translate('it_can_be_possible_to_upload_all_types_of_audio,_video_and_documentation_and_software_files.') }}">
                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}"
                            alt="">
                    </span>
                        </div>
                    </th>
                @endif
            </tr>
            </thead>
            <tbody>

            @php
                $serial = 1;
            @endphp

            @foreach ($generateCombination as $combinationKey => $combination)
                <tr>
                    <td class="text-start">
                        {{ $serial++ }}
                    </td>
                    <td class="text-start">
                        <label for="" class="control-label text-wrap max-w-250">{{ $combination['variant_key'] }}</label>
                        <input type="hidden" class="min-w-max-content" name="digital_product_variant_key[{{ $combination['unique_key'] }}]"
                            value="{{ $combination['variant_key'] }}">
                    </td>
                    <td class="p-2">
                        <input type="number" name="digital_product_price[{{ $combination['unique_key'] }}]"
                            value="{{ usdToDefaultCurrency(amount: $combination['price']) }}" min="0" step="0.01"
                            class="form-control variation-price-input remove-symbol" required
                            placeholder="{{ translate('ex').': 100' }}">
                    </td>
                    <td class="p-2">
                        <input type="text" name="digital_product_sku[{{ $combination['unique_key'] }}]"
                            value="{{ strtoupper($combination['sku']) }}" class="form-control min-w-max-content store-keeping-unit"
                            required>
                    </td>

                    @if($digitalProductType == 'ready_product')
                        <td class="p-2">
                            <div class="variation-upload-item d-flex">
                                <label class="variation-upload-file flex-shrink-0 w-100 {{ $combination['file'] ? 'collapse' : '' }}">
                                    <input type="file" class="d-none"
                                            data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                            accept=""
                                            name="digital_files[{{ $combination['unique_key'] }}]">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/upload-icon.png') }}"
                                        alt="">
                                    <span>{{ translate('Upload_File') }}</span>
                                </label>

                                <div class="variation-upload-file uploading-item collapse border flex-shrink-0 w-100">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/upload-icon.png') }}"
                                        alt="">
                                    <span class="me-auto text-dark">{{ translate('Uploading') }}</span>
                                    <button class="no-gutter cancel-upload" type="button">
                                        <span class="btn btn-circle bg-body-light text-white" style="--size: 1rem;"><i class="fi fi-rr-cross-small d-flex"></i></span>
                                    </button>
                                </div>

                                <div
                                    class="variation-upload-file uploaded-item justify-content-between flex-shrink-0 w-100 {{ $combination['file'] ? '' : 'collapse' }}">
                                    <span class="me-auto text-dark line-1 file-name fw-normal d-block max-w-150px text-truncate">
                                        {{ $combination['file'] }}
                                    </span>

                                    @if($combination['file'])
                                        <button class="no-gutter cancel-upload digital-variation-file-delete-button"
                                                type="button"
                                                data-product="{{ $combination['product_id'] }}"
                                                data-variant="{{ $combination['variant_key'] }}">
                                            <span class="btn btn-circle bg-body-light text-white" style="--size: 1rem;"><i class="fi fi-rr-cross-small d-flex"></i></span>
                                        </button>
                                    @else
                                        <button class="no-gutter cancel-upload" type="button">
                                            <span class="btn btn-circle bg-body-light text-white" style="--size: 1rem;"><i class="fi fi-rr-cross-small d-flex"></i></span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if(count($generateCombination) <= 0)
    @if($digitalProductType == 'ready_product' && (!isset($request['extensions_type']) || count($request['extensions_type']) <= 0))
        <div class="card rest-part bg-animate mt-3">
            <div class="card-header d-flex justify-content-end align-items-center gap-3 border-0 pc-header-ai-btn shadow-none">
                <div class="flex-grow-1">
                    <h2 class="mb-1 fs-18">
                        {{ translate('File_Upload') }} <span class="text-danger">*</span>
                    </h2>
                    <p class="fs-12 mb-0">
                        {{ translate('Upload the product file that customers will receive after purchase.') }}
                    </p>
                </div>
            </div>
            <div class="card-body pt-0">
                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row gy-3">
                        <div class="col-lg-6">
                            <div class="h-100">
                                <div class="d-flex justify-content-center position-relative lg document-upload-container h-100">
                                    <div class="document-file-assets"
                                         data-picture-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/picture.svg') }}"
                                         data-document-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}"
                                         data-blank-thumbnail="{{ dynamicAsset(path: 'public/assets/back-end/img/file-placeholder.png') }}">
                                    </div>

                                        <?php
                                        $fileTypeForDigitalReadyUrl = 'file';
                                        if ($product?->digital_file_ready_full_url && isset($product?->digital_file_ready_full_url['key'])) {
                                            $digitalReadyUrlFileKey = $product?->digital_file_ready_full_url['key'] ?? '';
                                            $extDigitalReadyUrl = strtolower(pathinfo($digitalReadyUrlFileKey, PATHINFO_EXTENSION));

                                            $mapDigitalReadyUrl = [
                                                'jpg' => 'image',
                                                'jpeg' => 'image',
                                                'png' => 'image',
                                                'gif' => 'image',
                                                'pdf' => 'pdf',
                                                'zip' => 'zip',
                                            ];
                                            $fileTypeForDigitalReadyUrl = $mapDigitalReadyUrl[$extDigitalReadyUrl] ?? 'file';
                                        }
                                        ?>

                                    <div class="document-existing-file"
                                         data-file-url="{{ $product?->digital_file_ready_full_url && isset($product?->digital_file_ready_full_url['path']) ? $product?->digital_file_ready_full_url['path'] : '' }}"
                                         data-file-name="{{ $digitalReadyUrlFileKey ?? '' }}"
                                         data-file-type="{{ $fileTypeForDigitalReadyUrl }}">
                                    </div>

                                    <div class="position-absolute end-0 top-0 p-2 z-2 after_upload_buttons d-none">
                                        <div class="d-flex gap-3 align-items-center">
                                            <button type="button" class="btn btn-primary icon-btn doc_edit_btn" style="--size: 26px;">
                                                <i class="fi fi-sr-pencil"></i>
                                            </button>
                                            <a type="button" class="btn btn-success icon-btn doc_download_btn" style="--size: 26px;">
                                                <i class="fi fi-sr-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="document-upload-wrapper lg mw-100 doc-upload-wrapper">
                                        <input type="file" class="document_input z-index-1"
                                               name="digital_file_ready"
                                               data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                               data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize(type: 'file').' '.'MB' }}"
                                               accept=".jpg,.jpeg,.png,.gif,.zip,.pdf,.xlsx"/>
                                        <div class="textbox">
                                            <img class="svg"
                                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/doc-upload-icon.svg') }}"
                                                 alt="">
                                            <p class="mb-3">
                                                {{ translate('Select_a_file_or') }}
                                                <span class="fw-semibold">
                                                {{ translate('Drag_and_Drop_here') }}
                                            </span>
                                            </p>
                                            <button type="button" class="btn btn-outline-primary">
                                                {{ translate('Select_File') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="bg-warning bg-opacity-10 px-20 py-3 text-dark rounded-8 h-100 d-flex justify-content-center flex-column">
                                <h3 class="text-info-dark fs-16">{{ translate('instructions') }}</h3>
                                <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body fs-12">
                                    <li>{{ translate('please_upload_proper_file_for_this_item.') }}</li>
                                    <li>{{ translate('after_attach_the_file_click_submit_button.') }}</li>
                                    <li>{{ translate('without_upload_any_file_this_item_don’t_show_at_website_or_app.') }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endif
