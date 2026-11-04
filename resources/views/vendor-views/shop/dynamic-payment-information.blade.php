@if (!empty($data))
    @foreach ($data as $item)
        <div class="form-group">
            <label class="form-label text-dark">
                {{ translate($item['input_name']) }}
                <span class="text-danger">{{ $item['is_required'] ? '*' : '' }}</span>
            </label>
            <input type="{{ $item['input_type'] == 'phone' ? 'tel' : $item['input_type'] }}" class="form-control"
                   placeholder="{{ translate($item['placeholder']) }}"
                   name="method_info[{{ $item['input_name'] }}]"
                {{ $item['is_required'] ? 'required' : '' }}>
        </div>
    @endforeach
@endif
