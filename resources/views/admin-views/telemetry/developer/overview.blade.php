<div class="dev-grid dev-grid--3">
    {{-- Live API health, from the monitoring buckets. Unmeasured says so and explains which of the
         two zeros it is — "nothing called it" and "nothing is collecting" need different actions. --}}
    <x-k.card :title="translate('api_health')">
        @if ($data['health']['measured'])
            <div class="dev-metrics">
                <div><span class="k-num">{{ number_format($data['health']['requests']) }}</span><small>{{ translate('requests') }} · {{ $data['health']['range'] }}</small></div>
                <div><span class="k-num">{{ $data['health']['error_rate'] }}%</span><small>{{ translate('error_rate') }}</small></div>
                <div><span class="k-num">{{ $data['health']['p95'] ?? '—' }}<i>ms</i></span><small>p95</small></div>
                <div><span class="k-num">{{ $data['health']['p99'] ?? '—' }}<i>ms</i></span><small>p99</small></div>
            </div>
        @else
            <x-k.empty :title="translate('not_measured')" :text="$data['health']['reason']" />
        @endif
    </x-k.card>

    {{-- Documentation quality as a number that moves. Without it, "the portal is incomplete" is an
         opinion; with it, it is a figure somebody can be asked to improve. --}}
    <x-k.card :title="translate('documentation_quality')">
        <div class="dev-score" data-score="{{ $data['quality']['score'] }}">
            <span class="k-num">{{ $data['quality']['score'] }}</span><i>/100</i>
        </div>
        <ul class="dev-list">
            <li><span>{{ translate('described_by_hand') }}</span><strong>{{ $data['quality']['documented_pct'] }}%</strong></li>
            <li><span>{{ translate('with_a_request_schema') }}</span><strong>{{ $data['quality']['schema_pct'] }}%</strong></li>
            <li><span>{{ translate('classified') }}</span><strong>{{ $data['quality']['classified_pct'] }}%</strong></li>
        </ul>
        <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.developer.section', ['section' => 'quality']) }}">
            {{ translate('what_is_missing') }}
        </a>
    </x-k.card>

    <x-k.card :title="translate('api_snapshots')">
        @if ($data['latest_snapshot'])
            <p class="dev-note">
                {{ translate('last_snapshot') }}: <strong>{{ $data['latest_snapshot']->label }}</strong>
                — {{ $data['latest_snapshot']->endpoint_count }} {{ translate('endpoints') }},
                {{ \Carbon\Carbon::parse($data['latest_snapshot']->captured_at)->diffForHumans() }}.
            </p>
        @else
            <p class="dev-note">{{ translate('no_snapshot_has_been_taken_yet_so_no_change_can_be_detected_against_a_previous_release') }}</p>
        @endif

        <form action="{{ route('admin.developer.snapshot') }}" method="POST" class="dev-inline-form">
            @csrf
            <input type="text" name="label" placeholder="{{ translate('release_name') }}" maxlength="96">
            <button type="submit" class="k-btn k-btn--sm">{{ translate('capture') }}</button>
        </form>
    </x-k.card>
</div>

<div class="dev-grid dev-grid--2">
    <x-k.card :title="translate('the_api_by_client')">
        <ul class="dev-list dev-list--bars">
            @foreach ($data['summary']['by_audience'] as $audience => $count)
                <li>
                    <a href="{{ route('admin.developer.section', ['section' => 'explorer', 'audience' => $audience]) }}">
                        {{ translate($audience) }}
                    </a>
                    <span class="dev-bar"><i style="width: {{ round(100 * $count / max(1, $data['summary']['api'])) }}%"></i></span>
                    <strong>{{ $count }}</strong>
                </li>
            @endforeach
        </ul>
    </x-k.card>

    <x-k.card :title="translate('recent_api_changes')">
        @forelse ($data['recent_changes'] as $change)
            <div class="dev-change dev-change--{{ $change->severity }}">
                <span class="dev-change__badge">{{ translate($change->severity === 'breaking' ? 'breaking' : $change->change_type) }}</span>
                <div>
                    <code>{{ $change->endpoint }}</code>
                    <small>{{ $change->detail }}</small>
                </div>
            </div>
        @empty
            <x-k.empty
                :title="translate('no_change_recorded_yet')"
                :text="translate('changes_are_detected_by_comparing_api_snapshots_capture_one_now_and_the_next_one_will_have_something_to_compare_against')" />
        @endforelse
    </x-k.card>
</div>
