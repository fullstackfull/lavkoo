{{--
    Security: who was refused, who was given power, and what this deployment cannot see.

    The definition comes before the numbers, and that ordering is the whole design. Everything
    counted here is an HTTP RESPONSE — a 401 means "not with those credentials, or none at all",
    which is what an expired session, a signed-out tab retrying an XHR and a guard that does not
    admit this user type all produce. It is not a rejected password, nothing in this application
    records one, and a page that let a reader assume otherwise would turn an ordinary afternoon of
    expired sessions into a reported brute-force attempt.

    Nothing here is an address. Sources are the digest the recorder already stored: a network,
    hashed with a salt that changes every day. The page says that out loud, because a "sources"
    table that silently forgets everyone at midnight would otherwise be read as a list of repeat
    offenders — and because an operator who cannot find an address here should know the omission is
    deliberate rather than go looking somewhere the redaction rules do not reach.
--}}

@php
    $window = $panel['window'];
    $collection = $panel['collection'];
    $refusals = $panel['refusals'];
    $sources = $panel['sources'];
    $credentials = $panel['credentials'];
    $volume = $panel['volume'];
    $signals = $panel['signals'];
    $activity = $panel['admin_activity'];
    $coverage = $panel['audit_coverage'];
    $occurrences = $panel['error_occurrences'];
    $privacy = $panel['privacy'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        // A block that read successfully and found nothing is a measurement, and must not borrow
        // the wording of a block that could not read at all.
        'ok' => translate('nothing_recorded_in_this_window'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Two refusals are a fault and one is a formality, so they do not share a colour: a 403 is a
    // caller who was identified and still turned away, which is the one worth looking at twice.
    $statusTone = static fn (int $status) => match ($status) {
        403 => 'mon-pill--critical',
        401 => 'mon-pill--warning',
        419 => 'mon-pill--info',
        429 => 'mon-pill--critical',
        default => 'mon-pill--unknown',
    };

    $severityTone = static fn (?string $severity) => match ($severity) {
        'critical' => 'mon-pill--critical',
        'warning' => 'mon-pill--warning',
        'success' => 'mon-pill--healthy',
        'info' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    $splitTone = ['a', 'b', 'c', 'd', 'e'];

    $refusalsDrawn = $refusals['state'] === 'ok' && !empty($refusals['rows']);
    $missingFamilies = array_keys(array_filter($coverage['families'] ?? [], static fn ($present) => $present === false));
@endphp

{{-- One fault, stated once. When the per-request log cannot answer, every count below is missing
     for the same single reason, and repeating it under six cards turns one gap into six. --}}
@if ($collection['state'] !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $collection['state'] === 'failed' ? 'critical' : 'warning' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('nothing_on_this_page_can_be_counted_for_this_window') }}</strong>
                <small>{{ $collection['note'] }}</small>
                <small>{{ translate('an_empty_refusal_table_below_is_the_absence_of_a_log_and_not_a_reading_of_zero_attempts') }}</small>
                @if (!empty($collection['remedy']))
                    <code>{{ $collection['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@elseif ($collection['range_exceeds_retention'])
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="clock" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('this_range_is_longer_than_the_request_log_is_kept_for') }}</strong>
                <small>{{ $collection['note'] }}</small>
            </span>
        </div>
    </div>
@endif

{{-- The single values, each rendering its own state so a count that could not be taken can never
     be drawn as a zero — which on this page would read as "nobody tried". --}}
<x-k.card :title="translate('security_at_a_glance')">
    <div class="mon-grid">
        @foreach ($panel['headline'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>

    {{-- The definition, before anything is read off the cards above it. --}}
    <p class="mon-note">{{ translate('what_these_numbers_are_and_what_they_are_not') }}:</p>
    <ul class="mon-note">
        @foreach ($refusals['by_status'] as $status)
            <li>
                <span class="mon-pill {{ $statusTone((int) $status['status']) }}">{{ $status['status'] }}</span>
                {{ translate($status['meaning']) }}.
            </li>
        @endforeach
        <li>
            <strong>{{ translate('a_refusal_is_not_a_rejected_password') }}</strong>
            {{ translate('these_are_http_responses_counted_one_row_per_request_whether_a_submitted_credential_was_wrong_is_a_different_measurement_and_this_application_does_not_take_it') }}.
        </li>
        <li>
            {{ translate('no_address_appears_anywhere_on_this_page') }} —
            {{ translate('a_source_is_a_network_hashed_with_a_salt_that_changes_every_day_so_it_can_be_grouped_and_never_traced_or_blocked') }}.
        </li>
    </ul>
</x-k.card>

{{-- What was refused, by what refused it and where. --}}
<x-k.card :title="translate('authentication_and_authorisation_refusals')">
    @if ($refusals['state'] === 'ok')
        @if ((int) $refusals['total'] > 0)
            <div class="mon-split" role="img" aria-label="{{ translate('share_of_refusals_by_status') }}">
                @foreach ($refusals['by_status'] as $status)
                    @if (($status['share_pct'] ?? 0) > 0)
                        <span class="mon-split__part mon-split__part--{{ $splitTone[$loop->index % count($splitTone)] }}"
                              style="inline-size: {{ $status['share_pct'] }}%"
                              title="{{ $status['status'] }}: {{ $count($status['hits']) }} ({{ $status['share_pct'] }}%)"></span>
                    @endif
                @endforeach
            </div>

            <ul class="mon-split__legend">
                @foreach ($refusals['by_status'] as $status)
                    <li class="mon-split__key">
                        <span class="mon-split__swatch mon-split__part--{{ $splitTone[$loop->index % count($splitTone)] }}" aria-hidden="true"></span>
                        <span>{{ $status['status'] }}</span>
                        <span class="k-num">{{ $count($status['hits']) }}<i>{{ $status['share_pct'] === null ? '—' : $status['share_pct'] . '%' }}</i></span>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- A measured zero, said as one. The window holds recorded requests and none of them
                 was refused; that is a reading, and drawing an empty state here would report it as
                 an absence of evidence instead. --}}
            <div class="mon-attention">
                <div class="mon-attention__item mon-attention__item--info">
                    <x-k.icon name="check" :size="16" />
                    <span class="mon-attention__body">
                        <strong>{{ translate('no_request_in_this_window_was_refused') }}</strong>
                        <small>{{ $refusals['note'] }}</small>
                    </span>
                </div>
            </div>
        @endif

        @if ($refusalsDrawn)
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('status') }}</th>
                        <th>{{ translate('path') }}</th>
                        <th>{{ translate('channel') }}</th>
                        <th class="k-table__num">{{ translate('refusals') }}</th>
                        <th class="k-table__num">{{ translate('share') }}</th>
                        <th>{{ translate('first_seen') }}</th>
                        <th>{{ translate('last_seen') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($refusals['rows'] as $row)
                        <tr>
                            <td><span class="mon-pill {{ $statusTone((int) $row['status']) }}">{{ $row['status'] }}</span></td>
                            <td>
                                <code>{{ $row['path'] ?? '—' }}</code>
                                @if ($row['meaning'])
                                    <small class="mon-metric__note" style="display:block">{{ translate($row['meaning']) }}</small>
                                @endif
                            </td>
                            <td>{{ $row['channel'] ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $count($row['hits']) }}</td>
                            <td class="k-table__num k-num">{{ $row['share_pct'] === null ? '—' : $row['share_pct'] . '%' }}</td>
                            <td class="k-num">{{ $row['first_at'] ?? '—' }}</td>
                            <td class="k-num">{{ $row['last_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if (!empty($refusals['by_user_type']))
                <p class="mon-note">
                    {{ translate('who_was_refused') }}:
                    @foreach ($refusals['by_user_type'] as $type)
                        {{ $type['user_type'] ?? translate('not_recorded') }}
                        {{ $count($type['hits']) }}@if ($type['share_pct'] !== null) ({{ $type['share_pct'] }}%)@endif{{ $loop->last ? '' : ',' }}
                    @endforeach
                </p>
            @endif

            <p class="mon-note">
                {{ translate('refused_out_of_recorded_in_this_window') }}:
                {{ $count($refusals['total']) }} / {{ $count($refusals['requests_in_window']) }}
                @if ($refusals['rate_pct'] !== null)
                    ({{ $refusals['rate_pct'] }}%)
                @endif
                — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            </p>

            @if ($refusals['truncated'])
                <p class="mon-note">{{ translate('more_paths_were_refused_than_this_page_lists_so_the_table_is_cut_rather_than_the_total_being_wrong') }}.</p>
            @endif
        @endif
    @else
        <x-k.empty icon="alert" :title="$stateTitle($refusals['state'])" :text="$refusals['note'] ?? ''" />
        @if (!empty($refusals['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $refusals['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Where the refusals came from, as a pseudonym that expires. --}}
<x-k.card :title="translate('sources_the_refusals_came_from')">
    @if ($sources['state'] === 'ok' && !empty($sources['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('source_digest') }}</th>
                    <th class="k-table__num">{{ translate('refusals') }}</th>
                    <th class="k-table__num">{{ translate('distinct_paths') }}</th>
                    <th class="k-table__num">{{ translate('share') }}</th>
                    <th>{{ translate('last_seen') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($sources['rows'] as $row)
                    <tr>
                        <td><code class="k-num" title="{{ $row['digest'] }}">{{ $row['short'] }}…</code></td>
                        <td class="k-table__num k-num">{{ $count($row['refusals']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['paths']) }}</td>
                        <td class="k-table__num k-num">{{ $row['share_pct'] }}%</td>
                        <td class="k-num">{{ $row['last_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- The accounting, so the table is never mistaken for the whole of the refusals above. --}}
        <p class="mon-note">
            {{ translate('refusals_attributed_to_a_source_digest') }}:
            {{ $count($sources['attributed']) }} / {{ $count($refusals['total']) }}.
            {{ translate('the_remainder_could_not_be_attributed') }}
            ({{ $count($sources['unattributed']) }}) —
            {{ translate('api_and_mobile_app_requests_carry_no_visit_session_and_therefore_no_source_digest') }}.
        </p>

        @if ($sources['truncated'])
            <p class="mon-note">{{ translate('more_sources_were_recorded_than_this_page_lists') }}.</p>
        @endif
    @else
        <x-k.empty icon="customers" :title="$stateTitle($sources['state'])" :text="$sources['note'] ?? ''" />
        @if (!empty($sources['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_read_this') }}</summary>
                <code>{{ $sources['remedy'] }}</code>
            </details>
        @endif
    @endif

    {{-- What the digest is, and what it therefore cannot do. Drawn whether or not the table has
         rows, because the constraint is a property of the data rather than of this window. --}}
    <ul class="mon-note">
        <li>{{ translate('a_source_is_the_callers_network_masked_to_a_24_or_64_and_then_hashed_it_is_never_an_address_and_cannot_be_turned_back_into_one') }}.</li>
        <li>{{ translate('the_salt_contains_the_date_so_the_same_network_appears_under_a_different_digest_tomorrow_and_cannot_be_recognised_across_two_days') }}.</li>
        @if ($sources['window_crosses_midnight'])
            <li class="mon-note--critical">{{ translate('this_window_crosses_midnight_so_one_source_active_on_both_sides_of_it_is_listed_twice_under_two_digests') }}.</li>
        @endif
        <li>{{ translate('nothing_here_can_be_blocked_from_this_page_a_blocklist_needs_a_real_address_which_is_a_privacy_decision_rather_than_a_missing_feature') }}.</li>
    </ul>
</x-k.card>

{{-- The number this application does not have, said plainly rather than filled in from the one
     next to it. --}}
<x-k.card :title="translate('rejected_credentials')">
    @if ($credentials['state'] === 'ok' && !empty($credentials['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('when') }}</th>
                    <th>{{ translate('action') }}</th>
                    <th>{{ translate('actor') }}</th>
                    <th>{{ translate('subject') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($credentials['rows'] as $row)
                    <tr>
                        <td class="k-num">{{ $row['occurred_at'] ?? '—' }}</td>
                        <td><code>{{ $row['action'] ?? '—' }}</code></td>
                        <td>
                            {{ $row['actor_name'] ?? '—' }}
                            @if ($row['actor_type'])
                                <small class="mon-metric__note" style="display:block">{{ $row['actor_type'] }}</small>
                            @endif
                        </td>
                        <td><code>{{ $row['subject_type'] ?? '—' }}</code> {{ $row['subject_id'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $credentials['note'] }}</p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($credentials['state'])" :text="$credentials['note'] ?? ''" />
        @if (!empty($credentials['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $credentials['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">
            {{ translate('until_that_exists_the_refusal_counts_above_are_the_only_authentication_signal_this_deployment_has_and_they_count_responses_rather_than_credentials') }}.
        </p>
    @endif
</x-k.card>

{{-- Recorded volume abuse: a limiter that actually fired, not an inference from a busy minute. --}}
<x-k.card :title="translate('requests_refused_by_a_rate_limiter')">
    @if ($volume['state'] === 'ok' && !empty($volume['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('path') }}</th>
                    <th>{{ translate('channel') }}</th>
                    <th class="k-table__num">{{ translate('throttled') }}</th>
                    <th>{{ translate('last_seen') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($volume['rows'] as $row)
                    <tr>
                        <td><code>{{ $row['path'] ?? '—' }}</code></td>
                        <td>{{ $row['channel'] ?? '—' }}</td>
                        <td class="k-table__num k-num">
                            <span class="mon-pill {{ $statusTone((int) $volume['status']) }}">{{ $count($row['hits']) }}</span>
                        </td>
                        <td class="k-num">{{ $row['last_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('counted_as_responses_carrying_status') }} {{ $volume['status'] }}.
            {{ translate('volume_that_stayed_under_the_configured_limit_leaves_no_trace_here_so_this_table_is_a_record_of_the_limiter_firing_and_not_a_measure_of_abuse') }}.
        </p>
        @if ($volume['truncated'])
            <p class="mon-note">{{ translate('more_paths_were_rate_limited_than_this_page_lists_so_the_total_is_withheld_rather_than_under_counted') }}.</p>
        @endif
    @else
        <x-k.empty icon="alert" :title="$stateTitle($volume['state'])" :text="$volume['note'] ?? ''" />
        @if (!empty($volume['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $volume['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Who did what, to which record. The values that changed are deliberately absent; see the note
     under the table, which names the columns this page will not read. --}}
<x-k.card :title="translate('privileged_actions_recorded')">
    @if ($activity['state'] === 'ok' && !empty($activity['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('when') }}</th>
                    <th>{{ translate('actor') }}</th>
                    <th>{{ translate('action') }}</th>
                    <th>{{ translate('subject') }}</th>
                    <th>{{ translate('fields_changed') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($activity['rows'] as $row)
                    <tr>
                        <td class="k-num">{{ $row['occurred_at'] ?? '—' }}</td>
                        <td>
                            {{ $row['actor_name'] ?? '—' }}
                            @if ($row['actor_type'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ $row['actor_type'] }}@if ($row['actor_id'] !== null) #{{ $row['actor_id'] }}@endif
                                </small>
                            @endif
                        </td>
                        <td>
                            <code>{{ $row['action'] ?? '—' }}</code>
                            @if ($row['module'])
                                <small class="mon-metric__note" style="display:block">{{ $row['module'] }}</small>
                            @endif
                        </td>
                        <td>
                            <code>{{ $row['subject_type'] ?? '—' }}</code>
                            @if ($row['subject_id'])
                                <small class="mon-metric__note" style="display:block">{{ $row['subject_id'] }}</small>
                            @endif
                        </td>
                        <td>
                            @if (!empty($row['changed_fields']))
                                @foreach ($row['changed_fields'] as $field)
                                    <code>{{ $field }}</code>{{ $loop->last ? '' : ' ' }}
                                @endforeach
                                @if ($row['fields_truncated'])
                                    <small class="mon-metric__note" style="display:block">
                                        +{{ $row['changed_field_count'] - count($row['changed_fields']) }} {{ translate('more') }}
                                    </small>
                                @endif
                            @else
                                <span class="mon-metric__state">{{ translate('no_field_diff_recorded') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('audited_actions_in_this_window') }}: {{ $count($activity['total']) }},
            {{ translate('distinct_actors') }}: {{ $count($activity['actors']) }}
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            @if ($activity['truncated'])
                {{ translate('the_table_lists_the_most_recent') }} {{ $activity['limit'] }}.
            @endif
        </p>

        @if (!empty($activity['actions']))
            <p class="mon-note">
                {{ translate('actions_recorded_in_this_window') }}:
                @foreach ($activity['actions'] as $action)
                    <code>{{ $action['action'] }}</code> ({{ $count($action['hits']) }}){{ $loop->last ? '' : ',' }}
                @endforeach
                @if ($activity['actions_truncated'])
                    — {{ translate('and_more_than_this_page_lists') }}
                @endif
            </p>
        @endif
    @else
        <x-k.empty icon="orders" :title="$stateTitle($activity['state'])" :text="$activity['note'] ?? ''" />
        @if (!empty($activity['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_read_this') }}</summary>
                <code>{{ $activity['remedy'] }}</code>
            </details>
        @endif
    @endif

    {{-- What the trail covers is whatever has been wired to the audit logger, so it is read back
         from the trail rather than claimed here. Naming the families that have never appeared is
         the difference between "no admin activity" and "no admin activity is recorded". --}}
    <p class="mon-note">{{ translate('what_this_trail_covers_and_what_it_does_not') }}:</p>
    <ul class="mon-note">
        @if (!empty($coverage['modules']))
            <li>
                {{ translate('modules_that_have_ever_written_to_it') }}:
                @foreach (array_keys($coverage['modules']) as $module)
                    <code>{{ $module }}</code>{{ $loop->last ? '' : ',' }}
                @endforeach
                @if ($coverage['truncated'])
                    — {{ translate('and_more_than_this_page_lists') }}
                @endif
            </li>
        @else
            <li>{{ translate('no_module_has_ever_written_to_this_trail_on_this_deployment') }}.</li>
        @endif

        @if (!empty($missingFamilies))
            <li class="mon-note--critical">
                {{ translate('never_recorded_here_at_all') }}:
                @foreach ($missingFamilies as $family)
                    <code>{{ $family }}.*</code>{{ $loop->last ? '' : ',' }}
                @endforeach
                — {{ translate('sign_ins_role_and_permission_changes_and_settings_writes_leave_no_line_in_this_trail_so_their_absence_from_it_is_not_evidence_that_none_happened') }}.
            </li>
        @endif

        <li>
            {{ translate('the_stored_row_also_holds_columns_this_page_deliberately_does_not_read') }}:
            @foreach ($activity['withheld'] as $column)
                <code>{{ $column }}</code>{{ $loop->last ? '' : ',' }}
            @endforeach
            — {{ translate('the_address_is_a_real_unmasked_one_and_the_before_and_after_documents_hold_the_changed_values_themselves_which_on_a_bank_details_row_is_exactly_what_must_never_be_drawn_on_a_dashboard') }}.
            {{ translate('the_field_names_are_shown_because_who_changed_which_fields_can_be_answered_without_the_contents') }}.
        </li>
    </ul>
</x-k.card>

{{-- Recorded signals on the monitoring timeline. Narrow by construction, and it says so. --}}
<x-k.card :title="translate('recorded_signals')">
    @if ($signals['state'] === 'ok' && !empty($signals['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('when') }}</th>
                    <th>{{ translate('type') }}</th>
                    <th>{{ translate('severity') }}</th>
                    <th>{{ translate('what_happened') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($signals['rows'] as $event)
                    <tr>
                        <td class="k-num">{{ $event['occurred_at'] ?? '—' }}</td>
                        {{-- The type was filtered against this panel's own allow-list, so it is
                             safe to translate; the severity column is free text at the database
                             level and is only translated when it is a value monitoring writes. --}}
                        <td>{{ translate($event['type']) }}</td>
                        <td>
                            <span class="mon-pill {{ $severityTone($event['severity']) }}">
                                {{ $event['severity_known'] ? translate($event['severity']) : ($event['severity'] ?? '—') }}
                            </span>
                        </td>
                        <td>
                            {{ $event['title'] ?? '—' }}
                            @if ($event['description'])
                                <small class="mon-metric__note" style="display:block">{{ $event['description'] }}</small>
                            @endif
                            @if ($event['key'])
                                <small class="mon-metric__note" style="display:block"><code>{{ $event['key'] }}</code></small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($signals['truncated'])
            <p class="mon-note">{{ translate('more_signals_were_recorded_than_this_page_lists') }}.</p>
        @endif
    @else
        <x-k.empty icon="alert" :title="$stateTitle($signals['state'])" :text="$signals['note'] ?? ''" />
        @if (!empty($signals['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $signals['remedy'] }}</code>
            </details>
        @endif
    @endif

    <p class="mon-note">
        {{ translate('read_from') }} <code>{{ $signals['source'] }}</code>.
        {{ translate('limited_to_the_event_types') }}:
        @foreach ($signals['types'] as $type)
            <code>{{ $type }}</code>{{ $loop->last ? '' : ',' }}
        @endforeach
        — {{ translate('this_axis_has_no_security_or_authentication_event_type_so_a_detected_intrusion_would_not_appear_on_it') }}.
    </p>
</x-k.card>

{{-- The same codes as the refusal table, from a different population. Kept apart on purpose. --}}
<x-k.card :title="translate('authorisation_failures_that_were_reported_as_exceptions')">
    @if ($occurrences['state'] === 'ok' && !empty($occurrences['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('when') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('route') }}</th>
                    <th>{{ translate('method') }}</th>
                    <th>{{ translate('channel') }}</th>
                    <th>{{ translate('user_type') }}</th>
                    <th>{{ translate('platform') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($occurrences['rows'] as $row)
                    <tr>
                        <td class="k-num">{{ $row['occurred_at'] ?? '—' }}</td>
                        <td>
                            @if ($row['status'] !== null)
                                <span class="mon-pill {{ $statusTone((int) $row['status']) }}">{{ $row['status'] }}</span>
                            @else
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @endif
                        </td>
                        <td><code>{{ $row['route'] ?? '—' }}</code></td>
                        <td>{{ $row['method'] ?? '—' }}</td>
                        <td>{{ $row['channel'] ?? '—' }}</td>
                        <td>{{ $row['user_type'] ?? translate('not_recorded') }}</td>
                        <td>{{ $row['platform'] ?? translate('not_recorded') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($occurrences['truncated'])
            <p class="mon-note">{{ translate('more_occurrences_were_recorded_than_this_page_lists') }}.</p>
        @endif
    @else
        <x-k.empty icon="reports" :title="$stateTitle($occurrences['state'])" :text="$occurrences['note'] ?? ''" />
        @if (!empty($occurrences['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $occurrences['remedy'] }}</code>
            </details>
        @endif
    @endif

    <p class="mon-note">
        {{ translate('this_table_holds_only_a_refusal_that_was_thrown_and_reported_as_an_exception_which_is_a_small_subset_of_the_responses_carrying_the_same_codes_above_the_two_counts_are_not_expected_to_agree') }}.
    </p>
</x-k.card>

<p class="mon-note">
    {{ translate('refusals_sources_and_rate_limiting_are_read_from') }}
    <code>telemetry_requests</code>, <code>visit_sessions</code> —
    {{ translate('one_row_per_request_kept_for') }} {{ $window['telemetry_retention_days'] }} {{ translate('days') }}.
    {{ translate('the_audit_trail_is_read_from') }} <code>audit_logs</code>.
    {{ translate('written_by_every_module_through') }} <code>App\Services\AuditLogger</code>.
    {{ translate('signals_and_reported_exceptions_come_from') }}
    <code>monitoring_events</code>, <code>monitoring_errors</code>.
    {{ translate('every_string_on_this_page_passes_through_the_monitoring_redactor_before_it_is_stored_in_the_payload_no_address_password_token_session_id_or_authorization_header_is_read_or_rendered_anywhere_here') }}.
</p>
