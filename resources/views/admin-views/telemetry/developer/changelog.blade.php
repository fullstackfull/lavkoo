{{-- Generated from snapshot diffs. Nobody writes these entries, which is the only way a changelog
     survives a busy quarter. --}}
<div class="dev-filters">
    @foreach (['' => 'all', 'breaking' => 'breaking', 'warning' => 'warning'] as $value => $label)
        <a class="k-chip {{ request()->query('severity') === ($value ?: null) ? 'is-active' : '' }}"
           href="{{ route('admin.developer.section', array_filter(['section' => 'changelog', 'severity' => $value])) }}">
            {{ translate($label) }}
        </a>
    @endforeach
</div>

<x-k.card>
    @forelse ($data['changes'] as $change)
        <div class="dev-change dev-change--{{ $change->severity }}">
            <span class="dev-change__badge">
                {{ $change->severity === 'breaking' ? translate('breaking') : translate($change->change_type) }}
            </span>
            <div>
                <code>{{ $change->endpoint }}</code>
                <small>{{ $change->detail }}</small>
                <span class="dev-muted">
                    {{ translate($change->detail_type) }} ·
                    {{ \Carbon\Carbon::parse($change->detected_at)->diffForHumans() }}
                    @if ($change->version) · {{ $change->version }} @endif
                </span>
            </div>
        </div>
    @empty
        <x-k.empty
            :title="translate('nothing_recorded_yet')"
            :text="translate('changes_appear_here_when_two_api_snapshots_can_be_compared_take_one_now_and_the_next_release_will_produce_a_changelog_by_itself')" />
    @endforelse
</x-k.card>

<x-k.card :title="translate('snapshots')">
    <table class="dev-table">
        <thead><tr><th>{{ translate('label') }}</th><th>{{ translate('version') }}</th><th>{{ translate('endpoints') }}</th><th>{{ translate('captured') }}</th></tr></thead>
        <tbody>
        @forelse ($data['snapshots'] as $snapshot)
            <tr>
                <td>{{ $snapshot->label }}</td>
                <td class="dev-muted">{{ $snapshot->app_version ?: '—' }}</td>
                <td>{{ $snapshot->endpoint_count }}</td>
                <td class="dev-muted">{{ \Carbon\Carbon::parse($snapshot->captured_at)->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="dev-muted">{{ translate('none_yet') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</x-k.card>
