@props(['key' => null, 'value' => null, 'remove' => null, 'tone' => null, 'invalid' => false])
<span {{ $attributes->merge(['class' => 'sc-chip' . ($tone ? ' sc-chip--' . $tone : '') . ($invalid ? ' sc-chip--invalid' : '')]) }}>
    @if ($key)<span class="sc-chip__key">{{ $key }}</span>@endif
    <span>{{ $slot->isEmpty() ? $value : $slot }}</span>
    @if ($remove)
        <a href="{{ $remove }}" class="sc-chip__x" aria-label="{{ translate('remove_filter') }}"><x-sc.icon name="x" :size="11" /></a>
    @endif
</span>
