{{-- The portal grading itself.

     The headline used to be the number of ENDPOINTS with a gap — "441 endpoints with gaps" — which
     reads as 441 problems and as a hopeless afternoon. It is three gaps, each shared by hundreds of
     endpoints: one is closed by writing an attribute, one by making validation readable, and one
     closes itself as traffic arrives. Saying which is which is the whole job of this screen. --}}
<div class="dev-grid dev-grid--2">
    <x-k.card :title="translate('score')">
        <div class="dev-score" data-score="{{ $data['score']['score'] }}">
            <span class="k-num">{{ $data['score']['score'] }}</span><i>/100</i>
        </div>
        <ul class="dev-list">
            <li><span>{{ translate('described_by_hand') }}</span><strong>{{ $data['score']['documented_pct'] }}%</strong></li>
            <li><span>{{ translate('with_a_request_schema') }}</span><strong>{{ $data['score']['schema_pct'] }}%</strong></li>
            <li><span>{{ translate('classified') }}</span><strong>{{ $data['score']['classified_pct'] }}%</strong></li>
        </ul>
    </x-k.card>

    <x-k.card :title="translate('what_is_missing')">
        <p class="dev-note">
            <strong>{{ $data['distinct_gaps'] }}</strong> {{ translate('distinct_gaps_shared_across') }}
            <strong>{{ number_format($data['total_flagged']) }}</strong>
            {{ translate('of') }} {{ number_format($data['api_endpoints']) }} {{ translate('api_endpoints') }}
        </p>
        <ul class="dev-list dev-list--bars">
            @foreach ($data['by_reason'] as $reason => $count)
                <li>
                    <span>{{ $reason }}</span>
                    {{-- Measured against the whole API surface, not against the flagged subset:
                         against the subset the largest reason is always a full bar, which reads as
                         "everything is broken" rather than "one gap, API-wide". --}}
                    <span class="dev-bar"><i style="width: {{ round(100 * $count / max(1, $data['api_endpoints'])) }}%"></i></span>
                    <strong>{{ $count }}</strong>
                </li>
            @endforeach
        </ul>
        <p class="dev-note">
            {{ translate('add_an_apidoc_attribute_to_a_controller_method_to_fix_the_first_two_the_attribute_lives_with_the_code_so_it_is_reviewed_in_the_same_diff_and_disappears_when_the_method_does') }}
        </p>
    </x-k.card>
</div>

{{-- The one gap nobody has to close by hand. It is worth its own card because the instinct on
     seeing a four-hundred-line list is to start typing, and this row of it will fill itself in. --}}
<x-k.card :title="translate('response_shapes_are_learned_from_real_traffic')">
    <div class="dev-metrics">
        <div>
            <span class="k-num">{{ number_format($data['observed']['with_success']) }}</span>
            <small>{{ translate('endpoints_seen_answering_successfully') }}</small>
        </div>
        <div>
            <span class="k-num">{{ number_format($data['observed']['endpoints']) }}</span>
            <small>{{ translate('endpoints_observed_at_all') }}</small>
        </div>
        <div>
            <span class="k-num">{{ number_format(max(0, $data['api_endpoints'] - $data['observed']['with_success'])) }}</span>
            <small>{{ translate('still_waiting_for_their_first_call') }}</small>
        </div>
    </div>
    <p class="dev-note">
        {{ translate('every_successful_api_response_teaches_the_portal_that_endpoints_shape_keys_and_types_only_never_a_value') }}
        {{ translate('only_a_2xx_closes_this_gap_an_endpoint_seen_answering_only_401_or_404_is_still_undescribed') }}
    </p>
</x-k.card>

<x-k.card :title="translate('endpoints_with_gaps')">
    @if ($data['total_flagged'] > $data['table_limit'])
        {{-- Said out loud: a table that silently shows 200 of 440 is a table that lies about the
             size of the job. --}}
        <p class="dev-note dev-muted">
            {{ translate('showing_the_first') }} {{ $data['table_limit'] }}
            {{ translate('of') }} {{ number_format($data['total_flagged']) }}
        </p>
    @endif
    <table class="dev-table">
        <thead><tr><th>{{ translate('endpoint') }}</th><th>{{ translate('client') }}</th><th>{{ translate('missing') }}</th></tr></thead>
        <tbody>
        @foreach ($data['endpoints'] as $warning)
            <tr>
                <td><a href="{{ route('admin.developer.endpoint', ['id' => $warning['id']]) }}"><code>{{ $warning['endpoint'] }}</code></a></td>
                <td class="dev-muted">{{ translate($warning['audience']) }}</td>
                <td class="dev-muted">{{ implode(' · ', $warning['missing']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-k.card>
