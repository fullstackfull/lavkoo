<x-k.card :title="translate('the_error_envelope')">
    <p class="dev-note">{{ $data['note'] }}</p>
    <pre class="dev-code"><code>{{ json_encode($data['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
</x-k.card>

<x-k.card :title="translate('what_each_status_means_here')">
    <table class="dev-table">
        <thead><tr>
            <th>{{ translate('status') }}</th><th>{{ translate('meaning') }}</th><th>{{ translate('what_to_do') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($data['statuses'] as $status)
                <tr>
                    <td><code>{{ $status['status'] }}</code></td>
                    <td>{{ $status['meaning'] }}</td>
                    <td class="dev-muted">{{ $status['note'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-k.card>
