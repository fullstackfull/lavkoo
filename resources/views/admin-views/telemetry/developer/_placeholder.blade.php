{{-- A section that exists in the navigation but has no screen yet.
     Deliberately explicit about what it will hold rather than showing an empty card: an
     unexplained blank page reads as a bug, and a developer who cannot tell "not built yet" from
     "broken" will file the wrong report. --}}
<x-k.card>
    <x-k.empty
        :title="translate($meta['label'])"
        :text="translate($meta['hint']) . '. ' . translate('this_section_has_no_screen_yet_the_data_behind_it_is_already_collected')" />
</x-k.card>
