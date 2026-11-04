<form action="{{ route('admin.system-setup.currency.system-currency-update') }}" method="post">
    @csrf
    <div class="d-flex flex-column align-items-center text-center mb-30">
        <img width="80" class="mb-20"
             src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/3rd-party-storage-image.png') }}"
             alt="">
        <h2 class="mb-2">{{ translate('switch_to_bdt_as_the_default_currency').' ?' }}</h2>
        <p class="mb-20 px-4">
            {{ translate('when_you_change_the_default_currency_all_listed_exchange_rates_will_automatically_update.') }}
            {{ translate('to_ensure_the_accuracy_please_manually_review_and_adjust_the_fractional_values.') }}
        </p>
    </div>
    <input type="hidden" name="default_currency_id" value="{{ $defaultCurrency?->id }}">
    <div class="table-responsive rounded-10 shadow-1 max-h-270 mb-20">
        <table class="k-table">
            <thead>
            <tr>
                <th>{{ translate('SL') }}</th>
                <th>{{ translate('Currency_Name') }}</th>
                <th>{{ translate('Exchange_Rate') }} {{ '(1 ' .$defaultCurrency?->code . ' = ?)' }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($currencyExchangeRate as $key => $currencyItem)
                <tr>
                    <th>{{ $key+1 }}</th>
                    <td>{{ $currencyItem?->name }}</td>
                    <td>
                        <input type="number" min="0" max="100000000" name="exchange_rate[{{ $currencyItem?->code }}]"
                               class="form-control h-30" id="exchange_rate" step="any"
                               value="{{ $currencyItem?->exchange_rate }}"
                               placeholder="{{ translate('ex').':'.'120' }}" required
                            {{ $currencyItem?->code == $defaultCurrency?->code ? 'readonly' : '' }}>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-end gap-3">
        <button type="button" class="btn btn-secondary max-w-120 flex-grow-1" data-bs-dismiss="modal">
            {{ translate('cancel') }}
        </button>
        <button type="submit" class="btn btn-primary max-w-120 flex-grow-1">
            {{ translate('update') }}
        </button>
    </div>
</form>
