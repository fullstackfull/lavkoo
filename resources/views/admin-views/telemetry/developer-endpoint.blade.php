@extends('layouts.admin.app')

@section('title', implode('|', $endpoint['methods']) . ' ' . $endpoint['path'])

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/developer.css') }}">
@endpush

@section('content')
    {{-- Everything about one endpoint on one page, because the alternative is a developer holding
         four tabs open to answer a single question. Reference, examples, live health, who still
         calls it and what has changed about it — in that order, because that is the order the
         questions arrive in. --}}
    <div class="content container-fluid k dev dev--endpoint">
        <a class="dev-back" href="{{ route('admin.developer.section', ['section' => 'explorer']) }}">
            ← {{ translate('back_to_the_api_explorer') }}
        </a>

        <header class="dev-endpoint-head">
            <div class="dev-endpoint-head__signature">
                @foreach ($endpoint['methods'] as $method)
                    <span class="dev-method dev-method--{{ strtolower($method) }}">{{ $method }}</span>
                @endforeach
                <code class="dev-endpoint-head__path" data-copy="{{ $endpoint['full_url'] }}">{{ $endpoint['path'] }}</code>
            </div>

            <p class="dev-endpoint-head__summary">{{ $endpoint['summary'] }}</p>
            @if ($endpoint['description'])
                <p class="dev-note">{{ $endpoint['description'] }}</p>
            @endif

            <div class="dev-endpoint-head__tags">
                <x-k.badge tone="info">{{ translate($endpoint['audience']) }}</x-k.badge>
                @if ($endpoint['version'])<x-k.badge tone="neutral">{{ $endpoint['version'] }}</x-k.badge>@endif
                <x-k.badge tone="neutral">{{ translate($endpoint['group']) }}</x-k.badge>
                <x-k.badge :tone="$endpoint['deprecated'] ? 'warning' : 'success'">{{ translate($endpoint['stability']) }}</x-k.badge>
                <x-k.badge tone="neutral">{{ translate($endpoint['visibility']) }}</x-k.badge>
                @if ($endpoint['idempotent'])
                    <x-k.badge tone="success" title="{{ translate('safe_to_retry_with_the_same_input') }}">{{ translate('idempotent') }}</x-k.badge>
                @endif
                @if ($endpoint['destructive'])
                    <x-k.badge tone="danger" title="{{ translate('this_changes_or_removes_data') }}">{{ translate('destructive') }}</x-k.badge>
                @endif
            </div>
        </header>

        @if ($endpoint['deprecated'])
            <div class="dev-callout dev-callout--warn">
                <strong>{{ translate('deprecated') }}@if ($endpoint['deprecated_since']) — {{ translate('since') }} {{ $endpoint['deprecated_since'] }}@endif</strong>
                @if ($endpoint['replaced_by'])
                    <p>{{ translate('use') }} <code>{{ $endpoint['replaced_by'] }}</code> {{ translate('instead') }}.</p>
                @endif
                @if ($endpoint['sunset_at'])
                    <p>{{ translate('scheduled_for_removal_on') }} {{ $endpoint['sunset_at'] }}.</p>
                @endif
                <p>{{ $endpoint['removal']['message'] }}</p>
            </div>
        @endif

        <div class="dev-grid dev-grid--2">
            <x-k.card :title="translate('authentication')">
                <p><strong>{{ translate($endpoint['auth']['mechanism']) }}</strong></p>
                @if ($endpoint['auth']['header'])
                    <pre class="dev-code"><code>{{ $endpoint['auth']['header'] }}</code></pre>
                @endif
                <p class="dev-note">{{ $endpoint['auth']['note'] }}</p>

                @if ($endpoint['permissions'] !== [])
                    <p class="dev-note">
                        {{ translate('also_requires') }}:
                        @foreach ($endpoint['permissions'] as $permission)<code>{{ $permission }}</code> @endforeach
                    </p>
                @endif
            </x-k.card>

            <x-k.card :title="translate('limits_and_identity')">
                <ul class="dev-list">
                    <li>
                        <span>{{ translate('rate_limit') }}</span>
                        <strong>
                            @if (($endpoint['rate_limit']['requests'] ?? null) !== null)
                                {{ $endpoint['rate_limit']['requests'] }} / {{ $endpoint['rate_limit']['minutes'] }} {{ translate('minute_s') }}
                            @else
                                {{ translate('only_the_api_group_limit') }}
                            @endif
                        </strong>
                    </li>
                    <li><span>{{ translate('route_name') }}</span><strong>{{ $endpoint['name'] ?: '—' }}</strong></li>
                    <li><span>{{ translate('since') }}</span><strong>{{ $endpoint['since'] ?: '—' }}</strong></li>
                </ul>
                {{-- Internal detail: which class serves this. Useful to the team, meaningless and
                     slightly revealing to an outside integrator, so it sits under a summary. --}}
                <details class="dev-details">
                    <summary>{{ translate('implementation') }}</summary>
                    <p><code>{{ class_basename($endpoint['controller'] ?? '') }}@{{ $endpoint['action'] }}</code></p>
                    <p class="dev-muted">{{ implode(' · ', $endpoint['middleware']) }}</p>
                </details>
            </x-k.card>
        </div>

        @if ($endpoint['path_parameters'] !== [] || $endpoint['body'] !== [])
            <x-k.card :title="translate('request')">
                @if ($endpoint['path_parameters'] !== [])
                    <h4 class="dev-subhead">{{ translate('path_parameters') }}</h4>
                    <table class="dev-table">
                        <thead><tr><th>{{ translate('name') }}</th><th>{{ translate('type') }}</th><th>{{ translate('required') }}</th></tr></thead>
                        <tbody>
                        @foreach ($endpoint['path_parameters'] as $parameter)
                            <tr>
                                <td><code>{{ $parameter['name'] }}</code></td>
                                <td>{{ $parameter['type'] ?? 'string' }}</td>
                                <td>{{ ($parameter['required'] ?? true) ? translate('yes') : translate('no') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($endpoint['body'] !== [])
                    <h4 class="dev-subhead">
                        {{ in_array('GET', $endpoint['methods'], true) ? translate('query_parameters') : translate('body') }}
                        {{-- Where these rules came from, stated plainly: recovered from a
                             FormRequest is a stronger guarantee than read out of a controller. --}}
                        <small class="dev-muted">
                            @if ($endpoint['body_source'] === 'form_request')
                                {{ translate('from') }} <code>{{ class_basename($endpoint['request_class']) }}</code>
                            @else
                                {{ translate('read_from_the_controllers_own_validation') }}
                            @endif
                        </small>
                    </h4>
                    <table class="dev-table">
                        <thead><tr>
                            <th>{{ translate('name') }}</th><th>{{ translate('type') }}</th>
                            <th>{{ translate('required') }}</th><th>{{ translate('rules') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($endpoint['body'] as $field)
                            <tr>
                                <td><code>{{ $field['name'] }}</code></td>
                                <td>{{ $field['type'] }}{{ isset($field['format']) ? ' (' . $field['format'] . ')' : '' }}</td>
                                <td>{{ !empty($field['required']) ? translate('yes') : translate('no') }}</td>
                                <td class="dev-muted">{{ implode('; ', $field['constraints'] ?? []) ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-k.card>
        @elseif (array_intersect(['POST', 'PUT', 'PATCH'], $endpoint['methods']) !== [])
            <x-k.card :title="translate('request')">
                <x-k.empty
                    :title="translate('no_request_schema_could_be_recovered')"
                    :text="translate('this_endpoint_writes_but_its_validation_is_not_written_as_a_literal_rule_array_so_nothing_can_be_extracted_add_a_formrequest_or_an_apidoc_attribute_to_document_it')" />
            </x-k.card>
        @endif

        <x-k.card :title="translate('code')">
            <div class="dev-tabs" data-dev-tabs>
                @foreach ($endpoint['examples'] as $key => $example)
                    <button type="button" class="dev-tabs__tab {{ $loop->first ? 'is-active' : '' }}" data-tab="{{ $key }}">
                        {{ $example['label'] }}
                    </button>
                @endforeach
            </div>
            @foreach ($endpoint['examples'] as $key => $example)
                <div class="dev-tabs__panel {{ $loop->first ? 'is-active' : '' }}" data-panel="{{ $key }}">
                    <button type="button" class="dev-copy" data-copy-target="code-{{ $key }}">{{ translate('copy') }}</button>
                    <pre class="dev-code"><code id="code-{{ $key }}">{{ $example['code'] }}</code></pre>
                </div>
            @endforeach
        </x-k.card>

        {{-- Try it. The one part of this page that DOES something, so it says what it will do
             before it does it: which of the three tiers this endpoint falls in, and what is
             missing when it is refused. The verdict is the server's — this only draws it. --}}
        @php($methods = array_keys($endpoint['console']))
        @php($firstVerdict = $endpoint['console'][$methods[0]] ?? ['allowed' => false])
        @if (!($mayUseConsole ?? true))
            {{-- Reading the documentation and firing requests at the platform are different
                 permissions now. The page still says the console exists and what it would need. --}}
            <x-k.card :title="translate('try_it')" id="dev-console">
                <p class="dev-callout dev-callout--warn">
                    {{ translate('sending_requests_from_the_console_needs_its_own_permission_ask_an_administrator_for_it') }}
                </p>
            </x-k.card>
        @else
        <x-k.card :title="translate('try_it')" id="dev-console">
            <div class="dev-console" data-console
                 data-url="{{ route('admin.developer.try', ['id' => $endpoint['id']]) }}"
                 data-verdicts="{{ json_encode($endpoint['console']) }}">

                <div class="dev-console__row">
                    <label class="dev-console__field">
                        <span>{{ translate('method') }}</span>
                        <select data-console-method>
                            @foreach ($methods as $method)
                                <option value="{{ $method }}">{{ $method }}</option>
                            @endforeach
                        </select>
                    </label>
                    <code class="dev-console__path">{{ $endpoint['path'] }}</code>
                </div>

                {{-- Refusals are shown in place of the send button, never beside it. --}}
                <p class="dev-callout dev-callout--warn" data-console-refusal hidden></p>

                <div data-console-form>
                    @if ($endpoint['path_parameters'] !== [])
                        <h4 class="dev-subhead">{{ translate('path_parameters') }}</h4>
                        <div class="dev-console__grid">
                            @foreach ($endpoint['path_parameters'] as $parameter)
                                <label class="dev-console__field">
                                    <span>{{ $parameter['name'] }}@if (($parameter['required'] ?? true))<i>*</i>@endif</span>
                                    <input type="text" data-console-path="{{ $parameter['name'] }}"
                                           placeholder="{{ $parameter['type'] ?? 'string' }}">
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @if ($endpoint['body'] !== [])
                        <h4 class="dev-subhead">
                            {{ in_array('GET', $endpoint['methods'], true) ? translate('query_parameters') : translate('body') }}
                        </h4>
                        <div class="dev-console__grid">
                            @foreach ($endpoint['body'] as $field)
                                <label class="dev-console__field">
                                    <span>{{ $field['name'] }}@if (!empty($field['required']))<i>*</i>@endif</span>
                                    <input type="text" data-console-field="{{ $field['name'] }}"
                                           placeholder="{{ $field['type'] }}">
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <div class="dev-console__grid">
                        <label class="dev-console__field">
                            {{-- Typed for one call and never stored: it is not saved, not logged,
                                 and redacted out of the transcript that comes back. --}}
                            <span>{{ translate('access_token_optional') }}</span>
                            <input type="password" autocomplete="off" data-console-token
                                   placeholder="{{ translate('sent_once_never_stored') }}">
                        </label>
                        <label class="dev-console__field" data-console-confirm-field hidden>
                            <span>{{ translate('type_the_method_to_confirm') }}</span>
                            <input type="text" autocomplete="off" data-console-confirm placeholder="POST">
                        </label>
                    </div>

                    <button type="button" class="btn btn--primary dev-console__send" data-console-send>
                        {{ translate('send') }}
                    </button>
                    <span class="dev-muted dev-console__tier" data-console-tier></span>
                </div>

                <div class="dev-console__result" data-console-result hidden>
                    <h4 class="dev-subhead">
                        <span data-console-status></span>
                        <small class="dev-muted"><span data-console-duration></span>ms</small>
                    </h4>
                    <pre class="dev-code"><code data-console-body></code></pre>
                    <p class="dev-note">{{ translate('secrets_are_removed_from_what_is_shown_here') }}</p>
                </div>
            </div>
        </x-k.card>
        @endif

        {{-- What it actually answers with. Nothing in this API declares a response type — the
             controllers return JSON directly — so the only honest source is what the endpoint has
             been seen answering. Keys and types are recorded; no value ever is. --}}
        <x-k.card :title="translate('what_it_answers_with')">
            @forelse ($endpoint['observed'] ?? [] as $observed)
                <h4 class="dev-subhead">
                    {{ $observed['method'] }} · {{ $observed['status'] }}
                    <small class="dev-muted">
                        {{ translate('observed_from') }} {{ number_format($observed['samples']) }}
                        {{ translate('real_response_s_most_recently') }} {{ $observed['last_seen_at'] }}
                    </small>
                </h4>
                <pre class="dev-code"><code>{{ json_encode($observed['shape'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
            @empty
                <x-k.empty
                    :title="translate('not_observed_yet')"
                    :text="translate('this_endpoint_has_not_been_called_since_response_shapes_started_being_recorded_so_nothing_is_claimed_about_what_it_returns')" />
            @endforelse
            <p class="dev-note">
                {{ translate('keys_and_types_only_no_value_from_any_response_is_stored') }}
            </p>
        </x-k.card>

        <div class="dev-grid dev-grid--2">
            <x-k.card :title="translate('live_usage')">
                @if ($endpoint['health']['measured'] ?? false)
                    <div class="dev-metrics">
                        <div><span class="k-num">{{ number_format($endpoint['health']['hits']) }}</span><small>{{ translate('requests') }} · {{ $endpoint['health']['range'] }}</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['error_rate'] }}%</span><small>{{ translate('errors') }}</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['p95'] ?? '—' }}<i>ms</i></span><small>p95</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['avg'] ?? '—' }}<i>ms</i></span><small>{{ translate('average') }}</small></div>
                    </div>
                    @if ($endpoint['health']['last_error'] ?? null)
                        <p class="dev-warn">
                            {{ translate('last_error') }}: <code>{{ $endpoint['health']['last_error']['type'] }}</code>
                            — {{ $endpoint['health']['last_error']['message'] }}
                        </p>
                    @endif
                @else
                    <x-k.empty
                        :title="translate('no_traffic_recorded')"
                        :text="$endpoint['health']['reason']['note'] ?? null ?? translate('nothing_has_called_this_endpoint_in_the_measured_window')" />
                @endif

                {{-- These numbers are a summary of what Monitoring recorded; the evidence behind
                     them — the individual slow requests, the failures — lives there, and having to
                     go and find the route by hand is the difference between a link and a search. --}}
                <p class="dev-note dev-note--links">
                    <a href="{{ route('admin.monitoring.section', ['section' => 'traces', 'route' => $endpoint['path'], 'range' => '24h']) }}">
                        {{ translate('traced_requests_for_this_route') }}
                    </a>
                    ·
                    <a href="{{ route('admin.monitoring.section', ['section' => 'requests', 'range' => '24h']) }}">
                        {{ translate('all_route_timings') }}
                    </a>
                    ·
                    <a href="{{ route('admin.monitoring.section', ['section' => 'errors', 'route' => $endpoint['path'], 'status' => 'all', 'range' => '24h']) }}">
                        {{ translate('errors_on_this_route') }}
                    </a>
                </p>
            </x-k.card>

            <x-k.card :title="translate('can_this_be_removed')">
                <p class="{{ ($endpoint['removal']['safe'] ?? null) === false ? 'dev-warn' : 'dev-note' }}">
                    {{ $endpoint['removal']['message'] }}
                </p>

                @if ($endpoint['callers']['measured'] ?? false)
                    <h4 class="dev-subhead">{{ translate('app_versions_calling_this_shop') }}</h4>
                    {{-- Named for what it measures. A session records the app version it came from,
                         not the endpoints it called, so this is the shop's version mix and not this
                         endpoint's — and a removal decision has to be made on the traffic figures
                         above, which are per-route. --}}
                    <p class="dev-muted">{{ $endpoint['callers']['note'] ?? '' }}</p>
                    <ul class="dev-list">
                        @foreach ($endpoint['callers']['versions'] as $caller)
                            <li><span>{{ $caller['version'] }}</span><strong>{{ $caller['share'] }}%</strong></li>
                        @endforeach
                    </ul>
                @else
                    <p class="dev-muted">{{ $endpoint['callers']['reason']['remedy'] ?? null ?? translate('app_versions_are_not_being_recorded') }}</p>
                @endif
            </x-k.card>
        </div>

        @if ($endpoint['changes'] !== [] || $endpoint['related'] !== [])
            <div class="dev-grid dev-grid--2">
                @if ($endpoint['changes'] !== [])
                    <x-k.card :title="translate('history')">
                        @foreach ($endpoint['changes'] as $change)
                            <div class="dev-change dev-change--{{ $change->severity }}">
                                <span class="dev-change__badge">{{ translate($change->change_type) }}</span>
                                <div>
                                    <small>{{ $change->detail }}</small>
                                    <span class="dev-muted">{{ \Carbon\Carbon::parse($change->detected_at)->diffForHumans() }}</span>
                                </div>
                            </div>
                        @endforeach
                    </x-k.card>
                @endif

                @if ($endpoint['related'] !== [])
                    <x-k.card :title="translate('related_endpoints')">
                        @foreach ($endpoint['related'] as $related)
                            <a class="dev-related" href="{{ route('admin.developer.endpoint', ['id' => $related['id']]) }}">
                                <span class="dev-method dev-method--{{ strtolower($related['methods'][0] ?? 'get') }}">{{ $related['methods'][0] ?? 'GET' }}</span>
                                <code>{{ $related['path'] }}</code>
                            </a>
                        @endforeach
                    </x-k.card>
                @endif
            </div>
        @endif
    </div>
@endsection

@push('script')
<script>
    // Tabs and copy buttons. Deliberately no framework: this page is a reference a developer
    // reads, and it should render instantly on a slow connection in a hotel.
    document.querySelectorAll('[data-dev-tabs]').forEach(function (tabs) {
        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab]');
            if (!tab) return;
            var root = tabs.parentElement;
            root.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.remove('is-active'); });
            root.querySelectorAll('[data-panel]').forEach(function (panel) { panel.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var panel = root.querySelector('[data-panel="' + tab.dataset.tab + '"]');
            if (panel) panel.classList.add('is-active');
        });
    });

    // The console. It does not decide anything: every refusal below was decided on the server and
    // is decided AGAIN there when send is pressed, so a console with its buttons re-enabled from a
    // developer console is a console that still cannot send what it may not send.
    (function () {
        var root = document.querySelector('[data-console]');
        if (!root) return;

        var verdicts = JSON.parse(root.dataset.verdicts || '{}');
        var methodSelect = root.querySelector('[data-console-method]');
        var refusal = root.querySelector('[data-console-refusal]');
        var form = root.querySelector('[data-console-form]');
        var confirmField = root.querySelector('[data-console-confirm-field]');
        var tierLabel = root.querySelector('[data-console-tier]');
        var result = root.querySelector('[data-console-result]');

        function verdict() {
            return verdicts[methodSelect.value] || {allowed: false};
        }

        function render() {
            var current = verdict();

            form.hidden = !current.allowed;
            refusal.hidden = !!current.allowed;
            confirmField.hidden = !current.needs_confirmation;
            tierLabel.textContent = current.tier === 'write' ? '{{ translate('this_writes_to_the_live_shop') }}' : '';

            if (!current.allowed) {
                refusal.textContent = current.message
                    || '{{ translate('the_console_will_not_send_this_request') }}';
                if (current.remedy) refusal.textContent += ' — ' + current.remedy;
            }
        }

        methodSelect.addEventListener('change', render);
        render();

        root.querySelector('[data-console-send]').addEventListener('click', function (button) {
            var payload = {};
            root.querySelectorAll('[data-console-field]').forEach(function (input) {
                if (input.value !== '') payload[input.dataset.consoleField] = input.value;
            });

            var path = {};
            root.querySelectorAll('[data-console-path]').forEach(function (input) {
                path[input.dataset.consolePath] = input.value;
            });

            var token = root.querySelector('[data-console-token]');
            var confirm = root.querySelector('[data-console-confirm]');

            fetch(root.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({
                    method: methodSelect.value,
                    path: path,
                    payload: payload,
                    token: token.value,
                    confirm: confirm.value
                })
            }).then(function (response) {
                return response.json().then(function (data) { return {status: response.status, data: data}; });
            }).then(function (answer) {
                // The token is used for one request and is not kept around afterwards, in the page
                // any more than on the server.
                token.value = '';

                result.hidden = false;

                if (!answer.data.ok) {
                    root.querySelector('[data-console-status]').textContent = answer.status;
                    root.querySelector('[data-console-duration]').textContent = '0';
                    root.querySelector('[data-console-body]').textContent =
                        (answer.data.message || '') + (answer.data.remedy ? ' — ' + answer.data.remedy : '');
                    return;
                }

                var sent = answer.data.response;
                root.querySelector('[data-console-status]').textContent = sent.status;
                root.querySelector('[data-console-duration]').textContent = sent.duration_ms;
                root.querySelector('[data-console-body]').textContent = sent.json !== null
                    ? JSON.stringify(sent.json, null, 2)
                    : (sent.text || '');
            }).catch(function () {
                result.hidden = false;
                root.querySelector('[data-console-body]').textContent =
                    '{{ translate('the_console_request_did_not_complete') }}';
            });
        });
    })();

    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var source = document.getElementById(button.dataset.copyTarget);
            if (!source) return;
            navigator.clipboard.writeText(source.textContent).then(function () {
                var original = button.textContent;
                button.textContent = '✓';
                setTimeout(function () { button.textContent = original; }, 1200);
            });
        });
    });
</script>
@endpush
