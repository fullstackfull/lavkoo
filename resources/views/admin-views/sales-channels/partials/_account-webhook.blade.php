{{-- Trendyol order-package webhook. Registering is a write; listing asks Trendyol only when the button is pressed.
     Expects $account, $webhook (address, statuses), $secretsSet, $writeState, $errors. --}}
@php($loaded = session('loaded_webhooks'))
<x-k.card :title="translate('trendyol_webhook')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('trendyol_webhook_help') }}</p>

    <label class="form-label fs-12" for="webhook-address">{{ translate('webhook_address_to_register') }}</label>
    <input class="form-control form-control-sm mb-2" id="webhook-address" type="text" readonly value="{{ $webhook['address'] }}" onfocus="this.select()">
    <div class="fs-12 mb-3">
        <x-k.badge :tone="($secretsSet['webhook_api_key'] ?? false) ? 'success' : 'neutral'">{{ ($secretsSet['webhook_api_key'] ?? false) ? translate('secret_is_set') : translate('secret_is_not_set') }}</x-k.badge>
        <span class="text-muted">{{ translate('webhook_key_help') }}</span>
    </div>

    <form method="POST" action="{{ route('admin.sales-channels.accounts.webhook', $account) }}" class="d-flex flex-column gap-2 mb-3">@csrf
        <details>
            <summary class="fs-12">{{ translate('limit_to_these_statuses') }}</summary>
            <div class="d-flex flex-wrap gap-3 mt-2">
                @foreach ($webhook['statuses'] as $status)
                    <label class="form-check mb-0 fs-12"><input class="form-check-input" type="checkbox" name="statuses[]" value="{{ $status }}"> <span class="form-check-label">{{ $status }}</span></label>
                @endforeach
            </div>
            <div class="fs-12 text-muted mt-1">{{ translate('all_statuses_when_none_is_ticked') }}</div>
        </details>
        @if ($writeState->sendsForReal())
            <label class="form-check mb-0 fs-12">
                <input class="form-check-input" type="checkbox" name="confirm_live" value="1">
                <span class="form-check-label">{{ translate('confirm_that_this_is_sent_for_real') }}</span>
            </label>
        @elseif ($writeState->isRehearsal())
            <div class="fs-12 text-muted">{{ translate('rehearsal_only_nothing_is_sent') }}</div>
        @endif
        @if ($errors->getBag('webhook')->any())<div class="text-danger fs-12" role="alert">{{ $errors->getBag('webhook')->first() }}</div>@endif
        <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('register_webhook') }}</button></div>
    </form>

    <form method="POST" action="{{ route('admin.sales-channels.accounts.webhook.list', $account) }}">@csrf
        <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('load_registered_webhooks') }}</button>
        <span class="fs-12 text-muted">{{ translate('load_registered_webhooks_help') }}</span>
    </form>

    @if (is_array($loaded))
        <div class="k-table-wrap table-responsive mt-3">
            <table class="k-table k-table--compact">
                <thead><tr><th>{{ translate('webhook_id') }}</th><th>{{ translate('webhook_address') }}</th><th>{{ translate('status') }}</th><th>{{ translate('statuses') }}</th></tr></thead>
                <tbody>
                @forelse ($loaded as $held)
                    <tr>
                        <td class="fs-12">{{ $held['id'] ?? '—' }}</td>
                        <td class="fs-12 text-break">{{ $held['url'] ?? '—' }}</td>
                        <td class="fs-12">{{ $held['status'] ?? '—' }}</td>
                        <td class="fs-12 text-break">{{ ($held['subscribedStatuses'] ?? '') !== '' ? $held['subscribedStatuses'] : translate('all_statuses') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">{{ translate('no_webhooks_registered') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-k.card>
