@props(['name', 'accept' => null, 'hint' => null])
<label {{ $attributes->merge(['class' => 'sc-upload']) }} data-sc-upload>
    <x-sc.icon name="upload-simple" :size="16" />
    <span class="sc-upload__prompt">{{ $slot->isEmpty() ? translate('choose_a_file_or_drop_it_here') : $slot }}</span>
    @if ($hint)<span class="sc-upload__hint">{{ $hint }}</span>@endif
    <input type="file" name="{{ $name }}" @if ($accept) accept="{{ $accept }}" @endif class="sc-visually-hidden">
</label>
