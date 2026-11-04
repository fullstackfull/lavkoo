@if(count($combinations) > 0)
    <div>
        <table class="k-table physical_product_show">
            <thead>
            <tr>
                <th class="text-start">
                    <label for="" class="control-label">
                        {{ translate('SL') }}
                    </label>
                </th>
                <th class="text-start">
                    <label for="" class="control-label">
                        {{ translate('attribute_Variation') }}
                    </label>
                </th>
                <th class="text-start">
                    <label for="" class="control-label">
                        {{ translate('variation_Wise_Price') }}
                        ({{ getCurrencySymbol() }})
                    </label>
                </th>
                <th class="text-start">
                    <label for="" class="control-label">
                        {{ translate('SKU') }}
                    </label>
                </th>
                <th class="text-start">
                    <label for="" class="control-label">
                        {{ translate('Variation_Wise_Stock') }}
                    </label>
                </th>
            </tr>
            </thead>
            <tbody>

            @php
                $serial = 1;
            @endphp

            @foreach ($combinations as $key => $combination)
                <tr>
                    <td class="text-start">
                        {{ $serial++ }}
                    </td>
                    <td>
                        <label for="" class="control-label text-wrap max-w-250">{{ $combination['type'] }}</label>
                        <input value="{{ $combination['type'] }}" name="type[]" class="d-none">
                    </td>
                    <td>
                        <input type="number" name="price_{{ $combination['type'] }}"
                               value="{{ usdToDefaultCurrency(amount: $combination['price']) }}" min="0"
                               step="0.01"
                               class="form-control min-w-max-content" required placeholder="{{ translate('ex').': 100'}}">
                    </td>
                    <td>
                        <input type="text" name="sku_{{ $combination['type'] }}" value="{{ $combination['sku'] }}"
                               class="form-control min-w-max-content store-keeping-unit" required>
                    </td>
                    <td>
                        <input type="number" name="qty_{{ $combination['type'] }}"
                               value="{{ $combination['qty'] }}" min="0" max="100000" step="1"
                               class="form-control min-w-max-content" placeholder="{{ translate('ex') }}: {{ translate('5') }}"
                               required>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
