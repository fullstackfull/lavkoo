{{-- Who creates this account's shipments and how a dispatch starts. Expects $setting (the account fields' reader), $labels and $shipmentCreationOptions. --}}
<div class="col-md-6">
    <label class="form-label" for="shipment-creation">{{ translate('shipment_creation_setting') }}</label>
    <select class="form-select" id="shipment-creation" name="settings[shipment_creation]">
        @foreach ($shipmentCreationOptions as $option)
            <option value="{{ $option }}" @selected($setting('shipment_creation', 'unset') === $option)>{{ $labels->shipmentCreation($option) }}</option>
        @endforeach
    </select>
    <div class="fs-12 text-muted">{{ translate('shipment_creation_setting_help') }}</div>
</div>
<div class="col-md-6">
    <label class="form-label" for="dispatch-mode">{{ translate('dispatch_mode_setting') }}</label>
    <select class="form-select" id="dispatch-mode" name="settings[dispatch_mode]">
        @foreach (['manual', 'auto'] as $option)
            <option value="{{ $option }}" @selected($setting('dispatch_mode', 'manual') === $option)>{{ $labels->dispatchMode($option) }}</option>
        @endforeach
    </select>
    <div class="fs-12 text-muted">{{ translate('dispatch_mode_setting_help') }}</div>
</div>
