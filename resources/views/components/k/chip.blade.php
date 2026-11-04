@props(['key' => null, 'removable' => true])
{{-- An applied filter states the field as well as the value, so a reader does not
     have to decode what a bare "Pending" refers to. --}}
<span {{ $attributes->merge(['class' => 'k-chip']) }}>
    @if ($key)<span class="k-chip__key">{{ $key }}:</span>@endif
    <span>{{ $slot }}</span>
    @if ($removable)
        <button type="button" class="k-chip__remove" aria-label="{{ translate('remove_filter') }}">
            <x-k.icon name="close" :size="12" />
        </button>
    @endif
</span>
