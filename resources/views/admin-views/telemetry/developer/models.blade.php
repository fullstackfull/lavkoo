{{--
    Models and enums: the values a client has to switch on.

    Read from the classes' own constants rather than written down beside them, because a reference
    maintained separately from what it describes is wrong within a month — and a client that cannot
    parse a status it has never seen fails on the day a new one is added.
--}}

@foreach ($data['enums'] ?? [] as $enum)
    <x-k.card :title="$enum['name']">
        <p class="mon-note" style="margin-block-start:0">
            {{ translate($enum['means']) }}. <code>{{ $enum['declared_in'] }}</code>
        </p>
        @if ($enum['values'] === null)
            {{-- Reported, not skipped: a reference silently one enum short is how a client ends up
                 unable to parse a value it was never told about. --}}
            <x-k.empty icon="alert" :title="translate('this_enumeration_could_not_be_read')"
                       :text="translate('the_constant_it_is_declared_in_has_been_renamed_or_removed')" />
        @else
            <div class="d-flex flex-wrap gap-2">
                @foreach ($enum['values'] as $value)
                    <code class="mon-pill">{{ $value }}</code>
                @endforeach
            </div>
        @endif
    </x-k.card>
@endforeach
