{{-- How this account may talk to the channel: its own switches, in the order the transport checks them, and what they add up to.
     Expects $account, $writeState, $globalEnabled, $externalWritesEnabled, $labels. --}}
<x-k.card :title="translate('how_this_account_may_talk_to_the_channel')" class="mb-3">
    <div class="alert alert-{{ $writeState->sendsForReal() ? 'warning' : 'info' }} fs-12 d-flex flex-wrap gap-2 align-items-start" role="status">
        <x-k.badge :tone="$writeState->tone()">{{ translate($writeState->labelKey()) }}</x-k.badge>
        <span>{{ translate($writeState->helpKey()) }}</span>
    </div>

    <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
        <li class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <span class="fs-12"><strong>{{ translate('channel_integrations') }}</strong> <code>SALES_CHANNELS_ENABLED</code>
                <span class="d-block text-muted">{{ $globalEnabled ? translate('channel_integrations_on_help') : translate('channel_integrations_off_help') }}</span></span>
            <x-k.badge :tone="$globalEnabled ? 'success' : 'danger'">{{ $globalEnabled ? translate('switched_on') : translate('switched_off') }}</x-k.badge>
        </li>
        <li class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <span class="fs-12"><strong>{{ translate('external_writes') }}</strong> <code>SALES_CHANNELS_EXTERNAL_WRITES_ENABLED</code>
                <span class="d-block text-muted">{{ $externalWritesEnabled ? translate('external_writes_on_help') : translate('external_writes_off_help') }}</span></span>
            <x-k.badge :tone="$externalWritesEnabled ? 'warning' : 'success'">{{ $externalWritesEnabled ? translate('switched_on') : translate('switched_off') }}</x-k.badge>
        </li>
        <li class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <span class="fs-12"><strong>{{ translate('account_switch') }}</strong>
                <span class="d-block text-muted">{{ translate('account_switch_help') }}</span></span>
            <form method="POST" action="{{ route('admin.sales-channels.accounts.toggle', ['account' => $account, 'action' => $account->enabled ? 'disable' : 'enable']) }}" class="d-flex gap-2 align-items-center">@csrf
                <x-k.badge :tone="$account->enabled ? 'success' : 'neutral'">{{ $account->enabled ? translate('enabled') : translate('disabled') }}</x-k.badge>
                <button class="btn btn-outline-primary btn-sm" type="submit">{{ $account->enabled ? translate('disable_account') : translate('enable_account') }}</button>
            </form>
        </li>
        <li class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <span class="fs-12"><strong>{{ translate('writes_on_this_account') }}</strong>
                <span class="d-block text-muted">{{ translate('writes_on_this_account_help') }}</span></span>
            <form method="POST" action="{{ route('admin.sales-channels.accounts.writes', $account) }}" class="d-flex gap-2 align-items-center">@csrf
                <input type="hidden" name="allowed" value="{{ $account->write_enabled ? 0 : 1 }}">
                <x-k.badge :tone="$account->write_enabled ? 'warning' : 'neutral'">{{ $account->write_enabled ? translate('writes_allowed') : translate('writes_not_allowed') }}</x-k.badge>
                <button class="btn btn-outline-primary btn-sm" type="submit">{{ $account->write_enabled ? translate('forbid_writes') : translate('allow_writes') }}</button>
            </form>
        </li>
        <li class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <span class="fs-12"><strong>{{ translate('dry_run_mode') }}</strong>
                <span class="d-block text-muted">{{ translate('dry_run_help') }}</span></span>
            <form method="POST" action="{{ route('admin.sales-channels.accounts.dry-run', $account) }}" class="d-flex flex-column gap-1 align-items-end">@csrf
                <input type="hidden" name="dry_run" value="{{ $account->dry_run ? 0 : 1 }}">
                <div class="d-flex gap-2 align-items-center">
                    <x-k.badge :tone="$account->dry_run ? 'info' : 'warning'">{{ $account->dry_run ? translate('rehearsal_only_nothing_is_sent') : translate('dry_run_off_writes_are_real') }}</x-k.badge>
                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ $account->dry_run ? translate('turn_dry_run_off') : translate('turn_dry_run_on') }}</button>
                </div>
                @if ($account->dry_run)
                    <label class="form-check mb-0 fs-12">
                        <input class="form-check-input" type="checkbox" name="confirm_live" value="1">
                        <span class="form-check-label">{{ translate('confirm_that_writes_may_leave_this_host') }}</span>
                    </label>
                    @if ($errors->getBag('dry_run')->has('confirm_live'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag('dry_run')->first('confirm_live') }}</div>@endif
                @endif
            </form>
        </li>
    </ul>
</x-k.card>
