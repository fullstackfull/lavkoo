{{--
    Creating or editing a rule, which nothing in this build could do.

    The whole alerting chain was already here — evaluator, cooldown machine, incident correlator,
    email notifier — and no route could write a rule, so changing a threshold was a hand-written
    INSERT and most installs had no rules at all. Every field that decides whether a rule becomes
    noise is on the form, because the two that stop it (`must hold for` and `cooldown`) are the ones
    an operator reaches for after the first bad night.
--}}
@if ($permissions->canEditSettings())
    <x-k.card :title="translate('add_or_change_a_rule')">
        <form action="{{ route('admin.monitoring.actions.alert-rule', ['action' => 'save']) }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="rule-key">{{ translate('key') }}</label>
                <input id="rule-key" name="key" class="form-control form-control-sm" maxlength="96" required
                       placeholder="cpu.usage" list="existing-rule-keys">
                <datalist id="existing-rule-keys">
                    @foreach ($rules['rows'] ?? [] as $rule)
                        <option value="{{ $rule['key'] }}">{{ $rule['name'] }}</option>
                    @endforeach
                </datalist>
                <small class="mon-metric__note">{{ translate('an_existing_key_edits_that_rule') }}</small>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="rule-name">{{ translate('name') }}</label>
                <input id="rule-name" name="name" class="form-control form-control-sm" maxlength="191" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="rule-metric">{{ translate('metric') }}</label>
                <input id="rule-metric" name="metric" class="form-control form-control-sm" maxlength="96" required
                       placeholder="http.error_rate">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="rule-label">{{ translate('label') }}</label>
                <input id="rule-label" name="label" class="form-control form-control-sm" maxlength="96">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="rule-operator">{{ translate('operator') }}</label>
                <select id="rule-operator" name="operator" class="form-control form-control-sm">
                    @foreach (\App\Services\Monitoring\Operations\MonitoringAlertRules::OPERATORS as $operator)
                        <option value="{{ $operator }}">{{ $operator }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="rule-warning">{{ translate('warning') }}</label>
                <input id="rule-warning" name="warning_threshold" type="number" step="any" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="rule-critical">{{ translate('critical') }}</label>
                <input id="rule-critical" name="critical_threshold" type="number" step="any" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="rule-recovery">{{ translate('recovers_below') }}</label>
                <input id="rule-recovery" name="recovery_threshold" type="number" step="any" class="form-control form-control-sm">
            </div>

            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="rule-for">{{ translate('must_hold_for_seconds') }}</label>
                <input id="rule-for" name="for_seconds" type="number" min="0" max="86400" value="120" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="rule-cooldown">{{ translate('cooldown_seconds') }}</label>
                <input id="rule-cooldown" name="cooldown_seconds" type="number" min="0" max="86400" value="900" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="rule-channels">{{ translate('email_these_addresses') }}</label>
                <input id="rule-channels" name="notify_channels" class="form-control form-control-sm" maxlength="191"
                       placeholder="{{ translate('the_shop_default_address_if_left_empty') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="rule-description">{{ translate('description') }}</label>
                <input id="rule-description" name="description" class="form-control form-control-sm" maxlength="191">
            </div>
            <div class="col-md-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="rule-enabled" name="enabled" value="1" checked>
                    <label class="form-check-label fs-12" for="rule-enabled">{{ translate('enabled') }}</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="rule-email" name="notify_email" value="1" checked>
                    <label class="form-check-label fs-12" for="rule-email">{{ translate('email') }}</label>
                </div>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('save') }}</button>
            </div>
        </form>
    </x-k.card>
@endif
