@if(count($product->digitalVariation) > 0)
    <div class="card-header">
        <div class="d-flex gap-2">
            <i class="tio-user-big"></i>
            <h4 class="mb-0">{{ translate('file_upload') }}</h4>
        </div>
    </div>

    <div class="card-body">
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    <th class="text-center">{{ translate('SL') }}</th>
                    <th class="text-center">{{ translate('Product_Variation') }}</th>
                    <th class="text-center">{{ translate('Price') }}
                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                    </th>
                    <th class="text-center">{{ translate('SKU') }}</th>
                    @if($product->digital_product_type == 'ready_product')
                        <th>
                            <div class="d-flex justify-content-center align-items-center gap-1">
                                <span>{{ translate('Upload_File') }}</span>
                                <span class="input-label-secondary cursor-pointer mb-1" data-bs-toggle="tooltip"
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

                @foreach($product->digitalVariation as $serial => $digitalVariation)
                    @php($uniqueKey = strtolower(str_replace('-', '_', $digitalVariation['variant_key'])))
                    <tr>
                        <td class="text-center">
                            {{ $serial+1 }}
                        </td>
                        <td class="text-center">
                            <label for="" class="control-label text-wrap max-w-250">{{ $digitalVariation['variant_key'] }}</label>
                            <input type="hidden" name="digital_product_variant_key[{{ $uniqueKey }}]"
                                   value="{{ $digitalVariation['variant_key'] }}">
                        </td>
                        <td>
                            <input type="number" name="digital_product_price[{{ $uniqueKey }}]" value="{{ usdToDefaultCurrency(amount: $combination['price']) }}"
                                   min="0" step="0.01"
                                   class="form-control variation-price-input remove-symbol" required
                                   placeholder="{{ translate('ex').': 100' }}">
                        </td>
                        <td>
                            <input type="text" name="digital_product_sku[{{ $uniqueKey }}]"
                                   value="{{ strtoupper($digitalVariation['sku']) }}"
                                   class="form-control store-keeping-unit" required>
                        </td>

                        @if($product->digital_product_type == 'ready_product')
                            <td>
                                <div class="variation-upload-item">
                                    <label class="variation-upload-file {{ $digitalVariation['file'] ? 'collapse' : '' }}">
                                        <input type="file" class="d-none" accept="" data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                               name="digital_files[{{ $uniqueKey }}]">
                                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/upload-icon.png') }}"
                                            alt="">
                                        <span>{{ translate('Upload_File') }}</span>
                                    </label>

                                    <div class="variation-upload-file uploading-item collapse">
                                        <img
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/upload-icon.png') }}"
                                            alt="">
                                        <span class="me-auto text-dark">{{ translate('Uploading') }}</span>
                                        <button class="no-gutter cancel-upload" type="button">
                                           <span class="btn btn-circle bg-body-light text-white" style="--size: 1rem;"><i class="fi fi-rr-cross-small d-flex"></i></span>
                                        </button>
                                    </div>

                                    <div class="variation-upload-file uploaded-item {{ $digitalVariation['file'] ? '' : 'collapse' }}">
                                        <span class="me-auto text-dark line-1 file-name fw-normal d-block max-w-150px text-truncate">
                                            {{ $digitalVariation['file'] }}
                                        </span>
                                        <button class="no-gutter cancel-upload" type="button">
                                            <span class="btn btn-circle bg-body-light text-white" style="--size: 1rem;"><i class="fi fi-rr-cross-small d-flex"></i></span>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
