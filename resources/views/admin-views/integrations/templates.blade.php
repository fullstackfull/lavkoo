@extends('layouts.admin.app')

@section('title', 'WhatsApp Template Automations')

@section('content')
<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">WhatsApp Template Automations</h1>
        <p class="text-muted">
            Map approved WhatsApp templates to system events and data fields.
        </p>
    </div>

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    @if ($templates->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <h4>No WhatsApp templates found</h4>

                <p class="text-muted mb-0">
                    Make sure the Chatwoot WhatsApp inbox is connected and contains approved templates.
                </p>
            </div>
        </div>
    @endif


    <div class="row g-4">

        @foreach ($templates as $template)

            @php
                $templateKey = md5(
                    ($template['name'] ?? '') .
                    ($template['language'] ?? '')
                );
            @endphp

            <div class="col-12">

                <div class="card">

                    {{-- Header --}}
                    <div class="card-header d-flex justify-content-between align-items-start gap-3">

                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">

                                <h4 class="mb-0">
                                    {{ $template['name'] }}
                                </h4>

                                <span class="badge bg-success">
                                    {{ $template['status'] }}
                                </span>

                                <span class="badge bg-secondary">
                                    {{ $template['language'] }}
                                </span>

                                @if ($template['category'])
                                    <span class="badge bg-info">
                                        {{ $template['category'] }}
                                    </span>
                                @endif

                            </div>

                            <div class="text-muted mt-2">
                                {{ $template['variables_count'] }}
                                variable{{ $template['variables_count'] == 1 ? '' : 's' }}
                            </div>
                        </div>

                        <div>
                            @if ($template['enabled'])
                                <span class="badge bg-success">
                                    Enabled
                                </span>
                            @else
                                <span class="badge bg-secondary">
                                    Disabled
                                </span>
                            @endif
                        </div>

                    </div>


                    <div class="card-body">

                        <div class="row g-4">

                            {{-- Template preview --}}
                            <div class="col-lg-5">

                                <h5 class="mb-3">
                                    Template Preview
                                </h5>

                                <div class="border rounded p-3 bg-light">

                                    @if ($template['header'])
                                        <div class="fw-bold mb-2">
                                            {{ $template['header'] }}
                                        </div>
                                    @endif

                                    @if ($template['body'])
                                        <div style="white-space: pre-wrap;">
                                            {{ $template['body'] }}
                                        </div>
                                    @endif

                                </div>

                                <div class="mt-3">

                                    <small class="text-muted d-block">
                                        Parameter format
                                    </small>

                                    <strong>
                                        {{ $template['parameter_format'] ?? 'N/A' }}
                                    </strong>

                                </div>

                            </div>


                            {{-- Automation settings --}}
                            <div class="col-lg-7">

                                <form
                                    action="{{ route('admin.integrations.templates.save') }}"
                                    method="POST"
                                >

                                    @csrf

                                    <input
                                        type="hidden"
                                        name="template_name"
                                        value="{{ $template['name'] }}"
                                    >

                                    <input
                                        type="hidden"
                                        name="template_language"
                                        value="{{ $template['language'] }}"
                                    >

                                    <input
                                        type="hidden"
                                        name="template_category"
                                        value="{{ $template['category'] }}"
                                    >


                                    {{-- Enabled --}}
                                    <div class="form-group mb-4">

                                        <div class="form-check form-switch">

                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                role="switch"
                                                id="enabled-{{ $templateKey }}"
                                                name="enabled"
                                                value="1"
                                                {{ $template['enabled'] ? 'checked' : '' }}
                                            >

                                            <label
                                                class="form-check-label"
                                                for="enabled-{{ $templateKey }}"
                                            >
                                                Enable this automation
                                            </label>

                                        </div>

                                    </div>


                                    {{-- Event --}}
                                    <div class="form-group mb-4">

                                        <label class="form-label">
                                            Use this template when
                                        </label>

                                     <select
    name="event"
    class="form-control notification-event-select"
    data-template="{{ $templateKey }}"
>
    <option value="">
        Select event
    </option>

    @foreach ($events as $eventKey => $eventLabel)
        <option
            value="{{ $eventKey }}"
            {{ $template['event'] === $eventKey ? 'selected' : '' }}
        >
            {{ $eventLabel }}
        </option>
    @endforeach
</select>

                                    </div>
<div
    class="form-group mt-3 order-status-condition"
    id="order-status-condition-{{ $templateKey }}"
    style="{{ $template['event'] === 'order_status_changed' ? '' : 'display:none;' }}"
>

    <label class="form-label">
        When status becomes
    </label>

    <select
        name="event_condition"
        class="form-control"
    >
        <option value="">
            Any Status
        </option>

        @foreach ($orderStatuses as $statusKey => $statusLabel)
            <option
                value="{{ $statusKey }}"
                {{ ($template['event_condition'] ?? null) === $statusKey ? 'selected' : '' }}
            >
                {{ $statusLabel }}
            </option>
        @endforeach
    </select>

</div>

                                    {{-- Recipient --}}
                                    <div class="form-group mb-4">

                                        <label class="form-label">
                                            Send to
                                        </label>

                                        <select
                                            name="recipient_field"
                                            class="form-control"
                                        >

                                            <option value="">
                                                Select recipient phone field
                                            </option>

                                            @foreach ($recipientFields as $fieldKey => $fieldLabel)

                                                <option
                                                    value="{{ $fieldKey }}"
                                                    {{ $template['recipient_field'] === $fieldKey ? 'selected' : '' }}
                                                >
                                                    {{ $fieldLabel }}
                                                </option>

                                            @endforeach

                                        </select>

                                    </div>


                                    {{-- Variables --}}
                                    @if (!empty($template['variables']))

                                        <div class="mb-4">

                                            <h5 class="mb-3">
                                                Variable Mapping
                                            </h5>

                                            <div class="border rounded">

                                                @foreach ($template['variables'] as $variable)

                                                    <div class="p-3 border-bottom">

                                                        <div class="row align-items-center g-3">

                                                            <div class="col-md-3">

                                                                <code class="fs-6">
                                                                    @{{{{ $variable }}}}
                                                                </code>

                                                            </div>

                                                            <div class="col-md-9">

                                                                <select
                                                                    name="variable_mapping[{{ $variable }}]"
                                                                    class="form-control"
                                                                >

                                                                    <option value="">
                                                                        Select data field
                                                                    </option>

                                                                    @foreach ($availableFields as $fieldKey => $fieldLabel)

                                                                        <option
                                                                            value="{{ $fieldKey }}"
                                                                            {{ (
                                                                                $template['variable_mapping'][$variable]
                                                                                ?? null
                                                                            ) === $fieldKey
                                                                                ? 'selected'
                                                                                : ''
                                                                            }}
                                                                        >
                                                                            {{ $fieldLabel }}
                                                                        </option>

                                                                    @endforeach

                                                                </select>

                                                            </div>

                                                        </div>

                                                    </div>

                                                @endforeach

                                            </div>

                                        </div>

                                    @else

                                        <div class="alert alert-info">
                                            This template has no BODY variables.
                                        </div>

                                    @endif


                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        Save Automation
                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        @endforeach

    </div>
@push('script')
<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.notification-event-select').forEach(function (select) {

        function updateStatusCondition() {

            const templateKey = select.dataset.template;

            const conditionBox = document.getElementById(
                'order-status-condition-' + templateKey
            );

            if (!conditionBox) {
                return;
            }

            if (select.value === 'order_status_changed') {
                conditionBox.style.display = '';
            } else {
                conditionBox.style.display = 'none';
            }
        }

        select.addEventListener('change', updateStatusCondition);

        updateStatusCondition();
    });

});
</script>
@endpush
</div>
@endsection