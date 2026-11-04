{{-- One visitor, in order. The report that no aggregate can answer, because every rollup in the
     system has thrown the sequence away by design. --}}
<x-k.card :title="translate('follow_one_visitor')">
    <form method="GET" action="{{ route('admin.analytics.section', ['section' => 'journeys']) }}" class="ana-inline-form">
        <input type="text" name="visitor" value="{{ $data['visitor'] }}"
               placeholder="{{ translate('visitor_id') }}" maxlength="64" autocomplete="off">
        <input type="hidden" name="range" value="{{ $window->key }}">
        <button type="submit" class="k-btn k-btn--sm">{{ translate('look_up') }}</button>
    </form>
    <p class="ana-note">{{ translate('a_visitor_id_is_the_random_first_party_cookie_value_it_is_not_a_name_an_email_or_anything_that_identifies_a_person_by_itself') }}</p>
</x-k.card>

@if ($data['journey'] === null)
    <x-k.card>
        <x-k.empty :title="translate('nobody_selected')" :text="translate('enter_a_visitor_id_above_to_see_every_session_and_event_recorded_for_it')" />
    </x-k.card>
@elseif (($data['journey']['state'] ?? '') !== 'ok')
    <x-k.card>
        <x-k.empty :title="translate('not_found')" :text="translate('no_visitor_with_that_id_has_been_recorded')" />
    </x-k.card>
@else
    @php($visitor = $data['journey']['visitor'])
    <x-k.card :title="translate('visitor')">
        <ul class="ana-list">
            <li><span>{{ translate('first_seen') }}</span><strong>{{ $visitor->first_seen_at }}</strong></li>
            <li><span>{{ translate('last_seen') }}</span><strong>{{ $visitor->last_seen_at }}</strong></li>
            <li><span>{{ translate('first_touch') }}</span><strong>{{ $visitor->first_source ?? '—' }} / {{ $visitor->first_medium ?? '—' }}</strong></li>
            <li><span>{{ translate('visits') }}</span><strong>{{ number_format($visitor->sessions) }}</strong></li>
            <li><span>{{ translate('orders') }}</span><strong>{{ number_format($visitor->orders) }}</strong></li>
            <li><span>{{ translate('lifetime_revenue') }}</span><strong>{{ number_format((float) $visitor->revenue, 2) }}</strong></li>
            @if ($visitor->identified_at)
                <li><span>{{ translate('signed_in_first_on') }}</span><strong>{{ $visitor->identified_at }}</strong></li>
            @endif
        </ul>
    </x-k.card>

    @foreach ($data['journey']['sessions'] as $session)
        <x-k.card :title="translate('visit') . ' — ' . $session->started_at">
            <p class="ana-note">
                {{ $session->source ?? '—' }} / {{ $session->medium ?? '—' }}
                @if ($session->campaign) · {{ $session->campaign }} @endif
                · {{ $session->device }} · {{ $session->pageviews }} {{ translate('pageviews') }}
                · {{ $session->duration_seconds }}s
            </p>
            @php($events = $data['journey']['events'][$session->id] ?? [])
            @if ($events === [])
                <p class="ana-muted">{{ translate('no_event_recorded_for_this_visit') }}</p>
            @else
                <ol class="ana-timeline">
                    @foreach ($events as $event)
                        <li>
                            <strong>{{ translate($event->name) }}</strong>
                            <span class="ana-muted">
                                {{ $event->entity_type ? $event->entity_type . ' ' . $event->entity_id : $event->path }}
                                · {{ \Carbon\Carbon::parse($event->occurred_at)->format('H:i:s') }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-k.card>
    @endforeach
@endif
