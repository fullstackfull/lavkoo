{{-- One shop at a time, for an operator who already knows which one they are here
     about. State lives in the URL so a filtered view stays shareable. --}}
<form method="GET" class="k-row mb-3" style="align-items:flex-end;gap:var(--k-size-3)">
    <div class="k-field" style="max-width:220px">
        <label class="k-field__label" for="seller_id">{{ translate('seller_id') }}</label>
        <input id="seller_id" type="number" name="seller_id" class="k-input"
               value="{{ requestString('seller_id') }}" placeholder="{{ translate('all_sellers') }}">
    </div>
    <div class="k-row">
        <x-k.button variant="primary" size="sm" type="submit">{{ translate('filter') }}</x-k.button>
        @if (requestString('seller_id') !== '' || requestString('status') !== '')
            <x-k.button variant="ghost" size="sm" :href="url()->current()">{{ translate('clear') }}</x-k.button>
        @endif
    </div>
</form>
