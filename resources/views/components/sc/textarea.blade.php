@props(['invalid' => false])
<textarea {{ $attributes->merge(['class' => 'sc-textarea' . ($invalid ? ' is-invalid' : '')]) }}>{{ $slot }}</textarea>
