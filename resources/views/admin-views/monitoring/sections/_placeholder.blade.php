{{--
    A section whose view has not been written yet.

    It reports that plainly instead of rendering an empty page, because an empty page reads as
    "there is no data" — a much more comforting claim than "this is not built", and exactly the
    confusion this system exists to avoid.
--}}
<x-k.card>
    <x-k.empty icon="settings"
               :title="translate('this_section_is_not_installed_in_this_build')"
               :text="translate('its_data_is_being_collected_the_view_for_it_is_not_part_of_this_release')" />
    @if (!empty($panel) && is_array($panel))
        <details class="mon-metric__remedy">
            <summary>{{ translate('raw_data_for_this_section') }}</summary>
            <pre class="mon-pre">{{ json_encode(collect($panel)->except('state')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) }}</pre>
        </details>
    @endif
</x-k.card>
