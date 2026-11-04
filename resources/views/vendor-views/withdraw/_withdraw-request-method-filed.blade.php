
@if($method_type == 'custom' && isset($vendorWithdrawMethod))
    @php($methodInfo = is_array($vendorWithdrawMethod->method_info) ? $vendorWithdrawMethod->method_info : json_decode($vendorWithdrawMethod->method_info, true))
    <div class="form-group">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex gap-2 align-items-center mb-2">
                    <img height="18" src="{{ dynamicAsset(path: 'public/assets/back-end/img/wallet-svg-icon.svg') }}"
                         alt="{{ $withdrawalMethod['method_name'] }}">
                    <h5 class="m-0">{{ $withdrawalMethod['method_name'] }}</h5>
                </div>
                <input type="hidden" name="request_method_type" value="vendor">
                <table class="table-borderless text--black" role="presentation">
                    <tbody>
                    @foreach ($withdrawalMethod?->method_fields as $methodField)
                        <tr class="bg-transparent">
                            <td class="p-1">{{ translate($methodField['input_name']) }}</td>
                            <td class="p-1">:</td>
                            <td class="p-1">
                                {{ $methodInfo[$methodField['input_name']] }}
                                <input type="hidden"
                                       name="method_info[{{ $methodField['input_name'] }}]"
                                       value="{{ $methodInfo[$methodField['input_name']] }}"
                                    {{ $methodField['is_required'] ? 'required' : '' }}>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@else
    @foreach ($withdrawalMethod?->method_fields as $methodField)
        <div class="form-group">
            <label class="form-label text-dark">
                {{ translate($methodField['input_name']) }}
                <span class="text-danger">{{ $methodField['is_required'] ? '*' : '' }}</span>
            </label>
            <input type="{{ $methodField['input_type'] == 'phone' ? 'tel' : $methodField['input_type'] }}"
                   class="form-control"
                   placeholder="{{ translate($methodField['placeholder']) }}"
                   name="method_info[{{ $methodField['input_name'] }}]"
                {{ $methodField['is_required'] ? 'required' : '' }}>
        </div>
    @endforeach
@endif

<input type="hidden" name="withdraw_method" value="{{ $withdrawalMethod['id'] }}">

<div class="form-group">
    <label class="form-label text-dark">
        {{ translate('Withdraw_Amount ') }}
        ({{ getCurrencySymbol() }})
        <span class="text-danger">*</span>
    </label>
    <input type="number" class="form-control" name="amount" step="any" min=".01" required
           @if(isset($withdrawalTotalAmount))
                placeholder="{{ translate('Ex') }}: {{ usdToDefaultCurrency(amount: $withdrawalTotalAmount) }}"
                max="{{ usdToDefaultCurrency(amount: $withdrawalTotalAmount + ($vendorWallet?->total_earning ?? 0)) }}"
                value="{{ usdToDefaultCurrency(amount: $withdrawalTotalAmount) }}"
           @else
                placeholder="{{ translate('Ex') }}: {{ usdToDefaultCurrency(amount: ($vendorWallet?->total_earning ?? 0)) }}"
                max="{{ usdToDefaultCurrency(amount: ($vendorWallet?->total_earning ?? 0)) }}"
           @endif
           >
</div>
