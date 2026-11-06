{{-- What calls the channel: syncs (reads) and publishes (writes). Each is a button that posts; opening this page calls nothing.
     Expects $account, $writeState, $syncStates, $publishing, $labels, $errors. --}}
@use('Illuminate\Support\Str')
<x-k.card :title="translate('synchronise')" :padded="false" class="mb-3">
    <p class="fs-12 text-muted p-3 pb-0 mb-0">{{ translate('synchronise_help') }}</p>
    <div class="k-table-wrap table-responsive">
        <table class="k-table">
            <thead><tr>
                <th>{{ translate('resource') }}</th><th>{{ translate('status') }}</th><th>{{ translate('last_success') }}</th><th>{{ translate('last_run_result') }}</th><th></th>
            </tr></thead>
            <tbody>
            @foreach (['orders', 'listings', 'inventory', 'finances', 'publish_stock', 'publish_price'] as $resource)
                @php($state = $syncStates->get($resource))
                @continue($state === null && str_starts_with($resource, 'publish_'))
                <tr>
                    <td>{{ $labels->syncResource($resource) }}</td>
                    <td>
                        @if ($state?->lease_expires_at?->isFuture())
                            <x-k.badge tone="info">{{ translate('running') }}</x-k.badge>
                        @elseif ($state?->backoff_until?->isFuture())
                            <x-k.badge tone="warning">{{ translate('backing_off_until') }} {{ $state->backoff_until->format('Y-m-d H:i') }}</x-k.badge>
                        @elseif (($state?->consecutive_failures ?? 0) > 0)
                            <x-k.badge tone="danger">{{ translate('failing') }} ({{ $state->consecutive_failures }})</x-k.badge>
                        @elseif ($state?->last_success_at)
                            <x-k.badge tone="success">{{ translate('ok') }}</x-k.badge>
                        @else
                            <x-k.badge>{{ translate('never_run') }}</x-k.badge>
                        @endif
                    </td>
                    <td class="fs-12">{{ $state?->last_success_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="fs-12">
                        @if ($state?->last_error)<div class="text-danger text-break">{{ Str::limit($state->last_error, 160) }}</div>@endif
                        @if ($state?->stats)<div class="text-muted">{{ $labels->statsLine((array) $state->stats) }}</div>@endif
                    </td>
                    <td>
                        @unless (str_starts_with($resource, 'publish_'))
                            <form method="POST" action="{{ route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => $resource]) }}">@csrf
                                <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('sync_now') }}</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-k.card>

<x-k.card :title="translate('publish_to_the_channel')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('publish_to_the_channel_help') }}</p>
    <dl class="row fs-12">
        <dt class="col-sm-6">{{ translate('linked_listings') }}</dt><dd class="col-sm-6">{{ $publishing['mapped'] }} / {{ $publishing['listings'] }}</dd>
        <dt class="col-sm-6">{{ translate('listings_opted_in_for_stock') }}</dt><dd class="col-sm-6">{{ $publishing['stock'] }}</dd>
        <dt class="col-sm-6">{{ translate('listings_opted_in_for_price') }}</dt><dd class="col-sm-6">{{ $publishing['price'] }}</dd>
    </dl>

    <form method="POST" action="{{ route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']) }}" class="d-flex flex-column gap-2">@csrf
        @if ($writeState->sendsForReal())
            <div class="alert alert-warning fs-12 py-2 mb-0" role="note">{{ translate('this_sends_to_the_channel_for_real') }}</div>
            <label class="form-check mb-0 fs-12">
                <input class="form-check-input" type="checkbox" name="confirm_live" value="1">
                <span class="form-check-label">{{ translate('confirm_that_this_is_sent_for_real') }}</span>
            </label>
        @elseif ($writeState->isRehearsal())
            <div class="alert alert-info fs-12 py-2 mb-0" role="note">{{ translate('rehearsal_only_nothing_is_sent') }}: {{ translate('rehearsal_publish_help') }}</div>
        @else
            <div class="alert alert-secondary fs-12 py-2 mb-0" role="note">{{ translate($writeState->helpKey()) }}</div>
        @endif
        @foreach (['stock', 'price', 'shipments'] as $kind)
            @if ($errors->getBag('publish_' . $kind)->has('confirm_live'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag('publish_' . $kind)->first('confirm_live') }}</div>@endif
        @endforeach
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-sm" type="submit">{{ translate('publish_confirmed_stock') }}</button>
            <button class="btn btn-primary btn-sm" type="submit" formaction="{{ route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'price']) }}">{{ translate('publish_manual_prices') }}</button>
            <button class="btn btn-outline-primary btn-sm" type="submit" formaction="{{ route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'shipments']) }}">{{ translate('report_linked_shipments') }}</button>
            <button class="btn btn-outline-secondary btn-sm" type="submit" formaction="{{ route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'settle']) }}">{{ translate('follow_up_what_the_channel_queued') }}</button>
        </div>
    </form>
</x-k.card>
