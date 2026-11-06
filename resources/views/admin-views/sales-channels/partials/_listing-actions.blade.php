{{-- What can be decided about one listing. Expects $listing, $account, $locations, $priceSources, $bag, $errors. Saving settings publishes nothing. --}}
@php($manualPrice = $listing->snapshot['manual_price'] ?? null)
<div class="d-flex flex-column gap-2">
    @if ($listing->status === 'suggested' && $listing->suggested_product_id)
        <form method="POST" action="{{ route('admin.sales-channels.products.accept-suggestion', $listing) }}">@csrf
            <button class="btn btn-primary btn-sm" type="submit">{{ translate('accept_suggestion') }}</button>
        </form>
    @endif

    @if ($listing->status !== 'ignored')
        <details>
            <summary class="fs-12">{{ $listing->status === 'mapped' ? translate('change_the_linked_product') : translate('link_to_a_product') }}</summary>
            <form method="POST" action="{{ route('admin.sales-channels.products.link', $listing) }}" class="d-flex flex-column gap-2 mt-2">@csrf
                @include('admin-views.sales-channels.partials._product-picker', ['id' => 'link-product-' . $listing->id, 'name' => 'product_id', 'label' => translate('find_a_product'), 'error' => $errors->getBag($bag)->first('product_id') ?: null])
                <div>
                    <label class="form-label fs-12 mb-0" for="variant-{{ $listing->id }}">{{ translate('variant_key_optional') }}</label>
                    <input class="form-control form-control-sm" id="variant-{{ $listing->id }}" name="variant_key" maxlength="191" value="{{ $listing->variant_key }}">
                </div>
                <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('link') }}</button></div>
            </form>
        </details>

        <details>
            <summary class="fs-12">{{ translate('publishing_settings') }}</summary>
            <form method="POST" action="{{ route('admin.sales-channels.products.settings', $listing) }}" class="d-flex flex-column gap-2 mt-2">@csrf
                <p class="fs-12 text-muted mb-0">{{ translate('saving_settings_publishes_nothing') }}</p>
                <div>
                    <label class="form-label fs-12 mb-0" for="location-{{ $listing->id }}">{{ translate('stock_location') }}</label>
                    <select class="form-select form-select-sm" id="location-{{ $listing->id }}" name="stock_location_id">
                        <option value="">{{ translate('not_set_policy_decides') }}</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) $listing->stock_location_id === (int) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label fs-12 mb-0" for="price-source-{{ $listing->id }}">{{ translate('price_source') }}</label>
                    <select class="form-select form-select-sm" id="price-source-{{ $listing->id }}" name="price_source">
                        @foreach ($priceSources as $source)
                            <option value="{{ $source }}" @selected(($listing->price_source ?: 'internal') === $source)>{{ $labels->priceSource($source) }}</option>
                        @endforeach
                    </select>
                    <div class="fs-12 text-muted">{{ $labels->priceSourceHelp($listing->price_source ?: 'internal') }}</div>
                </div>
                <div>
                    <label class="form-label fs-12 mb-0" for="manual-price-{{ $listing->id }}">{{ translate('manual_price') }}</label>
                    <input class="form-control form-control-sm" type="number" step="0.01" min="0" id="manual-price-{{ $listing->id }}" name="manual_price" value="{{ $manualPrice === null ? '' : number_format((float) $manualPrice, 2, '.', '') }}">
                    @if ($manualPrice === null)<span class="fs-12 text-muted">{{ translate('not_set') }}</span>@endif
                    @if ($errors->getBag($bag)->has('manual_price'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag($bag)->first('manual_price') }}</div>@endif
                </div>
                @include('admin-views.sales-channels.partials._switch', ['name' => 'inventory_publish', 'id' => 'inventory-publish-' . $listing->id, 'label' => translate('publish_confirmed_stock'), 'checked' => (bool) $listing->inventory_publish])
                @include('admin-views.sales-channels.partials._switch', ['name' => 'price_publish', 'id' => 'price-publish-' . $listing->id, 'label' => translate('publish_manual_prices'), 'checked' => (bool) $listing->price_publish])
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label fs-12 mb-0" for="buffer-{{ $listing->id }}">{{ translate('safety_buffer') }}</label>
                        <input class="form-control form-control-sm" type="number" min="0" id="buffer-{{ $listing->id }}" name="safety_buffer" value="{{ $listing->safety_buffer }}">
                    </div>
                    <div class="col-6">
                        <label class="form-label fs-12 mb-0" for="cap-{{ $listing->id }}">{{ translate('maximum_quantity') }}</label>
                        <input class="form-control form-control-sm" type="number" min="0" id="cap-{{ $listing->id }}" name="max_publish_quantity" value="{{ $listing->max_publish_quantity }}">
                    </div>
                </div>
                <div><button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('save_settings') }}</button></div>
            </form>
        </details>

        <form method="POST" action="{{ route('admin.sales-channels.products.ignore', $listing) }}" onsubmit="return confirm(@js(translate($listing->status === 'mapped' ? 'ignore_linked_listing_confirm' : 'ignore_listing_confirm')))">@csrf
            <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('ignore_listing') }}</button>
        </form>
    @else
        <form method="POST" action="{{ route('admin.sales-channels.products.unignore', $listing) }}">@csrf
            <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('restore_listing') }}</button>
        </form>
    @endif
</div>
