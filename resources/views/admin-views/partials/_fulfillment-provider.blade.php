{{-- "Fulfillment provider" card of the in-house product forms. Expects $product on edit; omit it on create. --}}
@if (\App\Services\Fulfillment\FulfillmentSchema::productColumnReady() && !request('product-gallery') && ($product->added_by ?? 'admin') === 'admin')
    @php
        $fulfillmentPanel = app(\App\Services\Fulfillment\ProductFulfillmentPanel::class)->for(isset($product) ? (int) $product->id : null);
        $canChooseProvider = \App\Utils\Helpers::module_permission_check(\App\Services\Fulfillment\ProductFormProviderSelection::PERMISSION);
    @endphp
    <div class="card card-body mt-3 rest-part">
        <div>
            <h2 class="mb-1">{{ translate('fulfillment_provider') }}</h2>
            <p class="fs-12 mb-3">{{ translate('fulfillment_provider_help') }}</p>
        </div>
        <div class="bg-section rounded-10 p-12 p-sm-20">
            <div class="d-flex flex-column gap-3">
                @foreach ($fulfillmentPanel['choices'] as $choice)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="{{ \App\Services\Fulfillment\ProductFormProviderSelection::FIELD }}"
                               id="fulfillment_provider_{{ $choice['value'] }}" value="{{ $choice['value'] }}"
                               @checked($choice['value'] === $fulfillmentPanel['current']) @disabled(!$canChooseProvider || !$choice['selectable'])>
                        <label class="form-check-label" for="fulfillment_provider_{{ $choice['value'] }}">
                            {{ $choice['provider'] ? $choice['provider']->label() : translate('fulfillment_provider_default') }}
                            <span class="d-block fs-12 text-muted">
                                {{ translate($choice['provider'] ? 'fulfillment_provider_hint_' . $choice['provider']->value : 'fulfillment_provider_hint_default') }}
                            </span>
                            @if ($choice['reason'])
                                <span class="d-block fs-12 text-danger">{{ translate($choice['reason']) }}</span>
                            @endif
                        </label>
                    </div>
                @endforeach
            </div>
            @unless ($canChooseProvider)
                <p class="fs-12 text-muted mt-3 mb-0">{{ translate('fulfillment_provider_permission_required') }}</p>
            @endunless
        </div>

        @if ($fulfillmentPanel['mappings']->isNotEmpty())
            <div class="mt-3">
                <h3 class="fs-14 fw-bold">{{ translate('provider_product_links') }}</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ translate('provider') }}</th>
                            <th>{{ translate('provider_product_id') }}</th>
                            <th>{{ translate('sku') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th>{{ translate('provider_stock') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($fulfillmentPanel['mappings'] as $mapping)
                            <tr>
                                <td>{{ config("fulfillment.providers.{$mapping->provider}.label", $mapping->provider) }}</td>
                                <td>{{ $mapping->external_id }}</td>
                                <td>{{ $mapping->external_sku ?? '—' }}</td>
                                <td>{{ translate('mapping_status_' . $mapping->status->value) }}</td>
                                <td>{{ $mapping->provider_stock ?? '—' }}
                                    <span class="d-block fs-12 text-muted">{{ translate('stock_source_' . $mapping->stock_source->value) }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($fulfillmentPanel['history']->isNotEmpty())
            <div class="mt-3">
                <h3 class="fs-14 fw-bold">{{ translate('fulfillment_provider_history') }}</h3>
                <ul class="fs-12 mb-0 ps-3">
                    @foreach ($fulfillmentPanel['history'] as $change)
                        <li>{{ $change->created_at?->format('Y-m-d H:i') }} —
                            {{ config("fulfillment.providers.{$change->from_provider}.label", translate('fulfillment_provider_default')) }}
                            → {{ config("fulfillment.providers.{$change->to_provider}.label", translate('fulfillment_provider_default')) }}
                            ({{ $change->actor_type }}{{ $change->actor_id ? ' #' . $change->actor_id : '' }})</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
