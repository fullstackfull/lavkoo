@extends('layouts.admin.app')

@section('title', translate('audit_log'))

@php
    $actorColours = ['admin' => 'accent', 'seller' => 'info', 'customer' => 'secondary', 'system' => 'secondary'];
@endphp

@php
    // What actually changed, rather than the word "changed". A row records the fields it wrote and
    // the values they had; showing only that a change happened made the two most useful columns in
    // the table unreadable — an auditor could see that the commission rate was touched and never
    // what it was moved from.
    $changedFields = static function ($entry): array {
        $before = is_array($entry->before) ? $entry->before : [];
        $after = is_array($entry->after) ? $entry->after : [];
        $changed = [];

        foreach (array_keys($before + $after) as $field) {
            $was = $before[$field] ?? null;
            $now = $after[$field] ?? null;

            if ($was === $now) {
                continue;
            }

            $changed[$field] = ['before' => $was, 'after' => $now];
        }

        return $changed;
    };

    $show = static function ($value): string {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? translate('yes') : translate('no');
        }
        if (is_array($value)) {
            return \Illuminate\Support\Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE), 120);
        }

        return \Illuminate\Support\Str::limit((string) $value, 120);
    };
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-time-past"></i>
                {{ translate('audit_log') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('who_did_what_to_which_record_and_when_append_only') }}</p>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="module">{{ translate('module') }}</label>
                        <select class="form-control" id="module" name="module" onchange="this.form.submit()">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($modules as $module)
                                <option value="{{ $module }}" {{ ($filters['module'] ?? '') === $module ? 'selected' : '' }}>
                                    {{ translate($module) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="actor_type">{{ translate('actor') }}</label>
                        <select class="form-control" id="actor_type" name="actor_type" onchange="this.form.submit()">
                            <option value="">{{ translate('all') }}</option>
                            @foreach (['admin', 'seller', 'customer', 'system'] as $actor)
                                <option value="{{ $actor }}" {{ ($filters['actor_type'] ?? '') === $actor ? 'selected' : '' }}>
                                    {{ translate($actor) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="search">{{ translate('search') }}</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="{{ $filters['search'] ?? '' }}"
                               placeholder="{{ translate('actor_action_or_record') }}">
                    </div>
                    <div class="col-sm-2">
                        <button class="btn btn-primary w-100">{{ translate('filter') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('when') }}</th>
                                <th>{{ translate('actor') }}</th>
                                <th>{{ translate('action') }}</th>
                                <th>{{ translate('record') }}</th>
                                <th>{{ translate('details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td class="fs-12 text-nowrap">{{ $entry->created_at?->format('d M Y H:i:s') }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $actorColours[$entry->actor_type] ?? 'secondary' }}">
                                            {{ translate($entry->actor_type ?? 'system') }}
                                        </span>
                                        <div class="fs-12">{{ $entry->actor_name }}@if ($entry->actor_id) #{{ $entry->actor_id }}@endif</div>
                                    </td>
                                    <td><code class="fs-12">{{ $entry->action }}</code></td>
                                    <td class="fs-12">
                                        @if ($entry->subject_type)
                                            {{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="fs-12">
                                        @if ($entry->context)
                                            @foreach ($entry->context as $key => $value)
                                                @if (!is_null($value) && $value !== '')
                                                    <span class="k-badge">{{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                                @endif
                                            @endforeach
                                        @endif
                                        @php($diff = $changedFields($entry))
                                        @if ($diff !== [])
                                            <details class="mt-1">
                                                <summary class="fs-12">{{ translate('changed') }} ({{ count($diff) }})</summary>
                                                <table class="k-table k-table--compact mt-1">
                                                    <thead><tr>
                                                        <th>{{ translate('field') }}</th>
                                                        <th>{{ translate('before') }}</th>
                                                        <th>{{ translate('after') }}</th>
                                                    </tr></thead>
                                                    <tbody>
                                                    @foreach ($diff as $field => $change)
                                                        <tr>
                                                            <td class="fs-12"><code>{{ $field }}</code></td>
                                                            <td class="fs-12 text-muted">{{ $show($change['before']) }}</td>
                                                            <td class="fs-12 fw-bold">{{ $show($change['after']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </details>
                                        @elseif ($entry->before || $entry->after)
                                            {{-- Recorded as a change, and the two states are equal:
                                                 a save that touched nothing. Said, not hidden. --}}
                                            <span class="k-badge">{{ translate('no_field_changed') }}</span>
                                        @endif
                                        @if ($entry->ip_address || $entry->user_agent)
                                            <div class="fs-10 text-muted mt-1">
                                                {{ $entry->ip_address ?: '—' }}
                                                @if ($entry->user_agent) · {{ \Illuminate\Support\Str::limit($entry->user_agent, 60) }} @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">{{ translate('no_activity_recorded_yet') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($entries, 'links')) {!! $entries->links() !!} @endif
            </div>
        </div>
    </div>
@endsection
