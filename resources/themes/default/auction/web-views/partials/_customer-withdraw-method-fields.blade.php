@if($method && !empty($method->method_fields))
    @php
        $numericKeywords = ['number', 'account', 'phone', 'mobile', 'routing', 'pin', 'zip'];
    @endphp
    <div class="card border-0 light-box rounded p-3 mt-1">
        <h6 class="fs-13 fw-semibold title-clr mb-3">{{ $method->method_name }}</h6>

        @foreach($method->method_fields as $field)
            @php
                $fieldName  = $field['input_name'] ?? '';
                $nameLower  = strtolower(str_replace(['_', '-'], ' ', $fieldName));
                $isNumeric  = collect($numericKeywords)->contains(fn($k) => str_contains($nameLower, $k));
            @endphp
            <div class="mb-3">
                <label class="form-label fs-13 title-semidark">
                    {{ translate($fieldName) }}
                    @if(!empty($field['is_required']))
                        <span class="text-danger">*</span>
                    @endif
                </label>
                <input type="text"
                       class="form-control fs-13"
                       name="method_info[{{ $fieldName }}]"
                       value="{{ $existingValues[$fieldName] ?? '' }}"
                       placeholder="{{ translate($fieldName) }}"
                       @if($isNumeric) inputmode="numeric" data-numeric-field="1" @endif
                       {{ !empty($field['is_required']) ? 'required' : '' }}>
            </div>
        @endforeach

    </div>
@endif
