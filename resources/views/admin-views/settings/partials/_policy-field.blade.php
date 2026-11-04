{{--
    One input per declared type. Kept apart from the page so the page stays a layout: a new policy
    type is an arm here rather than another branch inside the loop that renders every group.
--}}
@php($current = old($key, is_array($value) ? $value : (string) $value))

@switch($field['type'])
    @case('toggle')
        <select class="form-control" id="{{ $key }}" name="{{ $key }}">
            <option value="1" {{ $current ? 'selected' : '' }}>{{ translate('on') }}</option>
            <option value="0" {{ $current ? '' : 'selected' }}>{{ translate('off') }}</option>
        </select>
        @break

    @case('choice')
        <select class="form-control" id="{{ $key }}" name="{{ $key }}">
            @foreach ($field['options'] as $option)
                <option value="{{ $option }}" {{ (string) $current === (string) $option ? 'selected' : '' }}>{{ translate($option) }}</option>
            @endforeach
        </select>
        @break

    @case('multi_choice')
        <select class="form-control" id="{{ $key }}" name="{{ $key }}[]" multiple size="{{ min(count($field['options']), 6) }}">
            @foreach ($field['options'] as $option)
                <option value="{{ $option }}" {{ in_array($option, (array) $current, true) ? 'selected' : '' }}>{{ translate($option) }}</option>
            @endforeach
        </select>
        @break

    @case('time')
        <input type="time" class="form-control" id="{{ $key }}" name="{{ $key }}" value="{{ $current }}" required>
        @break

    @default
        <input type="number" class="form-control" id="{{ $key }}" name="{{ $key }}" value="{{ $current }}" required
               step="{{ $field['type'] === 'int' ? '1' : '0.01' }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}">
@endswitch
