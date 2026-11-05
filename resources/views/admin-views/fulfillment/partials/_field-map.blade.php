{{-- One verifiable field map. Expects $capability and $map (required, fields, stored). --}}
@php($stored = $map['stored'])
<div class="border rounded p-3 mb-3" data-field-map>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <strong>{{ translate('field_map_' . $capability) }}</strong>
        <x-k.badge :tone="$stored->verified ? 'success' : 'warning'">{{ $stored->verified ? translate('verified') . ' · ' . $stored->verifiedAt : translate('not_verified') }}</x-k.badge>
    </div>

    <form method="POST" action="{{ route('admin.fulfillment-providers.field-map.save', [$card['slug'], $capability]) }}">
        @csrf
        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <label class="form-label fs-12">{{ translate('items_path') }}</label>
                <input class="form-control form-control-sm" type="text" name="items_path" value="{{ $stored->itemsPath }}" placeholder="data">
            </div>
        </div>
        <div class="row g-2">
            @foreach ($map['fields'] as $field)
                <div class="col-md-4">
                    <label class="form-label fs-12">{{ translate('map_field_' . $field) }}@if (in_array($field, $map['required'], true)) <span class="text-danger">*</span>@endif</label>
                    <input class="form-control form-control-sm" type="text" name="paths[{{ $field }}]" value="{{ $stored->paths[$field] ?? '' }}" autocomplete="off">
                    <span class="fs-10 text-success" data-proposal="{{ $field }}"></span>
                </div>
            @endforeach
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-field-map-sample
                    data-url="{{ route('admin.fulfillment-providers.field-map.sample', [$card['slug'], $capability]) }}"
                    data-token="{{ csrf_token() }}" data-target="sample-{{ $capability }}" data-loading="{{ translate('loading') }}…">{{ translate('fetch_real_sample') }}</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-apply-proposal>{{ translate('use_proposed_paths') }}</button>
            <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('save_map') }}</button>
            <button class="btn btn-primary btn-sm" type="submit" name="verify" value="1">{{ translate('save_and_verify') }}</button>
        </div>
    </form>
    <pre class="bg-light rounded p-2 mt-3 mb-0 fs-11" id="sample-{{ $capability }}" style="max-height:260px;overflow:auto">{{ translate('sample_not_loaded') }}</pre>
</div>
