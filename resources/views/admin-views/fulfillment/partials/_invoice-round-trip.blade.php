{{-- Torod Point: the confirmation that the invoice number we send comes back in the order list. Expects $invoiceRoundTrip (confirmation, path, minEvidence, maxEvidence), $canOverrideGoLive. --}}
@php($confirmation = $invoiceRoundTrip['confirmation'])
<x-k.card :title="translate('invoice_round_trip')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('invoice_round_trip_help') }}</p>
    @if ($confirmation)
        <div class="alert alert-success fs-12 py-2">
            <strong>{{ translate('invoice_round_trip_confirmed') }}</strong>
            <div>{{ $confirmation['confirmed_at'] }} · {{ $confirmation['confirmed_by_name'] }} · <code>{{ $confirmation['invoice_path'] }}</code></div>
            <div class="text-break">{{ $confirmation['evidence'] }}</div>
        </div>
        @if ($canOverrideGoLive)
            <form method="POST" action="{{ route('admin.fulfillment-providers.torod.invoice-round-trip.withdraw') }}" onsubmit="return confirm(@js(translate('invoice_round_trip_withdraw_confirm')))">
                @csrf
                <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('withdraw_confirmation') }}</button>
            </form>
        @endif
    @else
        <div class="alert alert-warning fs-12 py-2">{{ translate('invoice_round_trip_unconfirmed_notice') }}</div>
        @if (!$invoiceRoundTrip['path'])
            <p class="fs-12 text-muted mb-0">{{ translate('invoice_round_trip_needs_invoice_path') }}</p>
        @elseif (!$canOverrideGoLive)
            <p class="fs-12 text-muted mb-0">{{ translate('invoice_round_trip_master_admin_only') }}</p>
        @else
            <form method="POST" action="{{ route('admin.fulfillment-providers.torod.invoice-round-trip.confirm') }}">
                @csrf
                <label class="form-label fs-12" for="invoice_round_trip_evidence">{{ translate('invoice_round_trip_evidence') }}</label>
                <textarea class="form-control form-control-sm" id="invoice_round_trip_evidence" name="evidence" rows="2" required
                          minlength="{{ $invoiceRoundTrip['minEvidence'] }}" maxlength="{{ $invoiceRoundTrip['maxEvidence'] }}">{{ old('evidence') }}</textarea>
                <div class="fs-12 text-muted">{{ translate('invoice_round_trip_evidence_help') }}</div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="invoice_round_trip_confirm" name="confirm" value="1" required>
                    <label class="form-check-label fs-12" for="invoice_round_trip_confirm"><strong>{{ translate('invoice_round_trip_statement') }}</strong> <code>{{ $invoiceRoundTrip['path'] }}</code></label>
                </div>
                <button class="btn btn-primary btn-sm mt-2" type="submit">{{ translate('confirm_invoice_round_trip') }}</button>
            </form>
        @endif
    @endif
</x-k.card>
