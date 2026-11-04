{{-- Found by looking for file, image or mimes rules — so an endpoint that starts accepting an
     upload appears here without anybody adding it to a list. --}}
<x-k.card :title="translate('file_uploads')">
    <p class="dev-note">{{ translate('send_these_as_multipart_form_data_not_json_the_allowed_types_and_sizes_below_are_the_validation_rules_the_endpoint_actually_enforces') }}</p>
</x-k.card>

@forelse ($data['endpoints'] as $endpoint)
    <x-k.card>
        @include('admin-views.telemetry.developer._endpoint-row', ['endpoint' => $endpoint])
        <table class="dev-table dev-table--tight">
            <tbody>
            @foreach ($endpoint['body'] as $field)
                @if (($field['type'] ?? null) === 'file')
                    <tr>
                        <td><code>{{ $field['name'] }}</code></td>
                        <td>{{ !empty($field['required']) ? translate('required') : translate('optional') }}</td>
                        <td class="dev-muted">{{ implode('; ', $field['constraints'] ?? []) ?: translate('no_type_or_size_constraint_is_declared') }}</td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </x-k.card>
@empty
    <x-k.card>
        <x-k.empty :title="translate('no_upload_endpoint_found')"
                   :text="translate('no_endpoint_declares_a_file_image_or_mimes_validation_rule')" />
    </x-k.card>
@endforelse
