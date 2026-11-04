{{-- The form controls one trigger or one action declares it needs.

     Nothing here knows what a threshold or a discount is. The field list, its types and its bounds
     come from the server's catalogue, which derives them from the very rule that will validate the
     value — so this cannot offer what the validator would refuse (handoff 13, cross-wave rule 4). --}}
@props(['fields' => [], 'name' => 'trigger_settings', 'values' => [], 'errors' => null])

@foreach ($fields as $field)
    @php
        $key = $field['key'];
        $inputName = $name . '[' . $key . ']';
        $id = $name . '_' . $key;
        $value = $values[$key] ?? null;
        $message = $errors?->first($name . '.' . $key);
    @endphp

    <x-sc.field :label="translate($field['label'])" :for="$id" :required="$field['required']" :error="$message">
        @if ($field['type'] === 'choice')
            <x-sc.select :id="$id" :name="$inputName" :value="$value"
                         :options="collect($field['options'])->map(fn ($option) => ['value' => $option, 'label' => translate('automation_option_' . $option)])->all()"
                         :invalid="(bool) $message" />
        @elseif ($field['type'] === 'id_list')
            {{-- Ids, comma separated, because the picker they belong in is a wave-8 component. The
                 server cleans and bounds whatever arrives, so a typo is refused rather than stored. --}}
            <x-sc.input :id="$id" :name="$inputName" type="text" :value="is_array($value) ? implode(',', $value) : $value"
                        :placeholder="translate('comma_separated_ids')" :invalid="(bool) $message" />
        @else
            {{-- A bound the field does not declare is passed as null, which Blade drops from the
                 rendered tag. Written as `@if` inside the tag it would not be a conditional
                 attribute at all: the component parser reads attributes before directives compile,
                 and the whole control disappears. --}}
            <x-sc.input :id="$id" :name="$inputName" num type="number"
                        :step="$field['type'] === 'integer' ? '1' : '0.01'"
                        :min="$field['min']" :max="$field['max']"
                        :value="$value" :invalid="(bool) $message" />
        @endif
    </x-sc.field>
@endforeach
