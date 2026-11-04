{{--
    What an operator does with an incident: take it, say what caused it, note what was tried, close it.

    Six columns of monitoring_incidents had no writer at all, so the console could say something was
    on fire and record nothing about what happened next. Cause attribution OFFERS the deploys that
    ran near the incident and lets a person choose — a tool that names a cause from a timestamp alone
    will eventually blame the deploy that happened to be nearby, and be believed.
--}}
@if ($permissions->canEditSettings())
    @php($candidates = app(\App\Services\Monitoring\Operations\MonitoringIncidents::class)->candidateDeployments($incident['id']))

    <div class="mon-incident-actions">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            @if (empty($incident['acknowledged_at']))
                <form action="{{ route('admin.monitoring.actions.incident', ['action' => 'acknowledge', 'id' => $incident['id']]) }}" method="post">
                    @csrf<button class="btn btn-outline-primary btn-sm">{{ translate('acknowledge') }}</button>
                </form>
            @else
                <span class="mon-pill mon-pill--ok">{{ translate('acknowledged') }} {{ $incident['acknowledged_at'] }}</span>
            @endif

            @if ($incident['is_open'])
                <form action="{{ route('admin.monitoring.actions.incident', ['action' => 'resolve', 'id' => $incident['id']]) }}" method="post"
                      onsubmit="return confirm('{{ translate('close_this_incident') }}?')">
                    @csrf<button class="btn btn-outline-success btn-sm">{{ translate('mark_resolved') }}</button>
                </form>
            @endif
        </div>

        <form action="{{ route('admin.monitoring.actions.incident', ['action' => 'attribute', 'id' => $incident['id']]) }}" method="post" class="row g-2 align-items-end mb-2">
            @csrf
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1" for="cause-{{ $incident['id'] }}">{{ translate('probable_cause') }}</label>
                <input id="cause-{{ $incident['id'] }}" name="probable_cause" class="form-control form-control-sm" maxlength="191"
                       value="{{ $incident['probable_cause'] ?? '' }}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1" for="evidence-{{ $incident['id'] }}">{{ translate('evidence') }}</label>
                <input id="evidence-{{ $incident['id'] }}" name="cause_evidence" class="form-control form-control-sm" maxlength="2000"
                       value="{{ $incident['cause_evidence'] ?? '' }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="deploy-{{ $incident['id'] }}">{{ translate('release_that_caused_it') }}</label>
                <select id="deploy-{{ $incident['id'] }}" name="deployment_id" class="form-control form-control-sm">
                    <option value="">{{ translate('none') }}</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate['id'] }}" {{ ($incident['deployment_id'] ?? null) === $candidate['id'] ? 'selected' : '' }}>
                            {{ $candidate['release'] }} — {{ $candidate['deployed_at'] }}
                        </option>
                    @endforeach
                </select>
                @if ($candidates === [])
                    <small class="mon-metric__note">{{ translate('no_release_was_recorded_near_this_incident') }}</small>
                @endif
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('save') }}</button>
            </div>
        </form>

        <form action="{{ route('admin.monitoring.actions.incident', ['action' => 'note', 'id' => $incident['id']]) }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-11">
                <label class="form-label fs-12 mb-1" for="note-{{ $incident['id'] }}">{{ translate('add_a_note') }}</label>
                <input id="note-{{ $incident['id'] }}" name="note" class="form-control form-control-sm" maxlength="2000" required
                       placeholder="{{ translate('what_was_tried_and_what_it_did') }}">
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-primary btn-sm w-100">{{ translate('add') }}</button>
            </div>
        </form>

        @if (!empty($incident['notes']))
            <pre class="mon-note" style="white-space:pre-wrap;margin-block-start:.5rem">{{ $incident['notes'] }}</pre>
        @endif
    </div>
@endif
