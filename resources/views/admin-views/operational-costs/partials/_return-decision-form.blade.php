{{-- One return decision: units to put back on the shelf and where, and damaged units to write off. For a claim the channel accepted the two numbers must add up to its units ($units); for a
     return a person records ($claim is null) the token makes a double submit one decision. Expects $line, $locations, $claim, $token, $units, $restockUnits and $defaultLocation. --}}
<form method="POST" action="{{ route('admin.operational-costs.returns.decide', $line->id) }}" class="row g-2 align-items-end mt-1" data-return-form>@csrf
    @if ($claim !== null)<input type="hidden" name="claim" value="{{ $claim }}">@else<input type="hidden" name="token" value="{{ $token }}">@endif
    <div class="col-12 fs-12 fw-semibold">{{ $claim !== null ? translate('return_accepted_by_the_channel') . ' · ' . $units . ' ' . translate('units') : translate('return_received') }}</div>
    <div class="col-md-3">
        <label class="form-label fs-12 mb-0" for="restock-{{ $line->id }}-{{ $claim ?? 'manual' }}">{{ translate('units_to_put_back_on_the_shelf') }}</label>
        <input class="form-control form-control-sm" type="number" min="0" step="1" id="restock-{{ $line->id }}-{{ $claim ?? 'manual' }}" name="units_restocked" value="{{ $restockUnits }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label fs-12 mb-0" for="writeoff-{{ $line->id }}-{{ $claim ?? 'manual' }}">{{ translate('damaged_units_to_write_off') }}</label>
        <input class="form-control form-control-sm" type="number" min="0" step="1" id="writeoff-{{ $line->id }}-{{ $claim ?? 'manual' }}" name="units_written_off" value="0" required>
    </div>
    <div class="col-md-3">
        <label class="form-label fs-12 mb-0" for="location-{{ $line->id }}-{{ $claim ?? 'manual' }}">{{ translate('put_back_at') }}</label>
        <select class="form-select form-select-sm" id="location-{{ $line->id }}-{{ $claim ?? 'manual' }}" name="location_id">
            <option value="">—</option>
            @foreach ($locations->where('seller_id', $line->order?->seller_id ?? 0) as $location)
                <option value="{{ $location->id }}" @selected((int) $defaultLocation === (int) $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label fs-12 mb-0" for="note-{{ $line->id }}-{{ $claim ?? 'manual' }}">{{ translate('note') }}</label>
        <input class="form-control form-control-sm" id="note-{{ $line->id }}-{{ $claim ?? 'manual' }}" name="note" maxlength="500">
    </div>
    <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ translate('record_the_return_decision') }}</button></div>
</form>
