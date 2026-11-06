{{-- The connection test and what it found. A test only reads, and a good result switches nothing on. Expects $account, $labels. --}}
<x-k.card :title="translate('connection')" class="mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <x-k.badge :tone="$account->connectionStatus()->isUsable() ? 'success' : ($account->connection_status === 'untested' ? 'neutral' : 'danger')">{{ translate($account->connectionStatus()->labelKey()) }}</x-k.badge>
        <span class="fs-12 text-muted">{{ translate('last_checked') }}: {{ $account->connection_checked_at?->format('Y-m-d H:i') ?? '—' }}</span>
    </div>
    @if ($account->connection_message)<p class="fs-12 text-break mb-2">{{ $account->connection_message }}</p>@endif
    @if ($account->last_error)<p class="fs-12 text-danger text-break mb-2">{{ translate('last_error') }}: {{ $account->last_error }}</p>@endif

    <div class="fs-12 mb-2">
        <div class="fw-semibold">{{ translate('granted_permissions') }}</div>
        @forelse ((array) $account->granted_permissions as $permission)<x-k.badge tone="success" :dot="false">{{ $permission }}</x-k.badge>@empty<span class="text-muted">—</span>@endforelse
    </div>
    <div class="fs-12 mb-3">
        <div class="fw-semibold">{{ translate('missing_permissions') }}</div>
        @forelse ((array) $account->missing_permissions as $permission)<x-k.badge tone="warning" :dot="false">{{ $permission }}</x-k.badge>@empty<span class="text-muted">—</span>@endforelse
    </div>

    <form method="POST" action="{{ route('admin.sales-channels.accounts.test', $account) }}">@csrf
        <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('test_connection') }}</button>
        <div class="fs-12 text-muted mt-1">{{ translate('test_connection_help') }}</div>
    </form>
</x-k.card>

<x-k.card :title="translate('account_facts')" class="mb-3">
    <dl class="row mb-0 fs-12">
        <dt class="col-5">{{ translate('seller') }}</dt>
        <dd class="col-7">{{ $account->seller_id === 0 ? translate('platform') : '#' . $account->seller_id }}</dd>
        <dt class="col-5">{{ translate('channel') }}</dt>
        <dd class="col-7">{{ translate($account->channelCode()?->labelKey() ?? 'channel') }}</dd>
        <dt class="col-5">{{ translate('environment') }}</dt>
        <dd class="col-7">{{ $labels->environment($account->environment) }}</dd>
        <dt class="col-5">{{ translate('market') }}</dt>
        <dd class="col-7">{{ $account->market ?: '—' }}</dd>
        <dt class="col-5">{{ translate('last_synced') }}</dt>
        <dd class="col-7">{{ $account->last_synced_at?->format('Y-m-d H:i') ?? '—' }}</dd>
        <dt class="col-5">{{ translate('created') }}</dt>
        <dd class="col-7">{{ $account->created_at?->format('Y-m-d H:i') ?? '—' }}</dd>
    </dl>
</x-k.card>
