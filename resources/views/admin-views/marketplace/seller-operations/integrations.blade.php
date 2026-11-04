@extends('layouts.admin.app')

@section('title', translate('keys_and_webhooks'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('keys_and_webhooks')"
                         :subtitle="translate('what_acts_as_a_shop_without_a_person_and_what_the_platform_calls')" />

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <x-k.card class="mb-4" :title="translate('api_keys')" :padded="false">
            <x-slot:actions>
                <span class="k-text-subtle">{{ translate('only_the_prefix_is_stored_the_key_itself_cannot_be_recovered') }}</span>
            </x-slot:actions>

            @if ($keys === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($keys->isEmpty())
                <x-k.empty icon="store" :title="translate('no_seller_has_issued_a_key_yet')"
                           :text="translate('a_key_is_created_by_a_seller_and_shown_to_them_once')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('name') }}</th>
                                <th>{{ translate('prefix') }}</th>
                                <th>{{ translate('scopes') }}</th>
                                <th>{{ translate('last_used') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($keys as $key)
                            <tr>
                                <td>{{ $shopName($key->seller_id) }}</td>
                                <td>{{ $key->name }}</td>
                                <td class="k-num k-text-muted">sk_seller_{{ $key->prefix }}…</td>
                                <td class="k-text-muted k-truncate" style="max-width:280px">
                                    {{-- A key with no scopes can read nothing, which is worth saying
                                         rather than leaving the cell blank. --}}
                                    {{ empty($key->scopes) ? translate('none') : implode(' · ', $key->scopes) }}
                                </td>
                                <td class="k-text-muted">
                                    {{ $key->last_used_at ?? translate('never_used') }}
                                </td>
                                <td>
                                    <x-k.badge :tone="$key->isUsable() ? 'success' : 'danger'">
                                        {{ translate($key->isUsable() ? 'active' : 'revoked') }}
                                    </x-k.badge>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        @if ($key->isUsable())
                                            <form method="POST"
                                                  action="{{ route('admin.marketplace.seller-operations.revoke-key') }}"
                                                  onsubmit="return confirm('{{ translate('anything_using_this_key_stops_working_immediately') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $key->id }}">
                                                <x-k.button variant="danger" size="sm" type="submit">
                                                    {{ translate('revoke') }}
                                                </x-k.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $keys->links() }}</div>
            @endif
        </x-k.card>

        <x-k.card :title="translate('webhook_endpoints')" :padded="false">
            @if ($health['installed'])
                <x-slot:actions>
                    <span class="k-text-subtle">
                        {{ translate('last_24h') }}:
                        {{ $health['delivered'] }} {{ translate('delivered') }} ·
                        {{ $health['failed'] }} {{ translate('failed') }} ·
                        {{ $health['pending'] }} {{ translate('still_trying') }}
                    </span>
                </x-slot:actions>
            @endif

            @if ($webhooks === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($webhooks->isEmpty())
                <x-k.empty icon="refresh" :title="translate('no_seller_has_added_an_endpoint_yet')"
                           :text="translate('nothing_is_called_until_a_seller_adds_one')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('name') }}</th>
                                <th>{{ translate('url') }}</th>
                                <th>{{ translate('events') }}</th>
                                <th>{{ translate('health') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($webhooks as $webhook)
                            <tr>
                                <td>{{ $shopName($webhook->seller_id) }}</td>
                                <td>{{ $webhook->name }}</td>
                                <td class="k-text-muted k-truncate" style="max-width:260px">{{ $webhook->url }}</td>
                                <td class="k-text-muted k-truncate" style="max-width:220px">
                                    {{ empty($webhook->events) ? translate('none') : implode(' · ', $webhook->events) }}
                                </td>
                                <td>
                                    @if ($webhook->status === 'disabled')
                                        <x-k.badge tone="danger">{{ translate('disabled') }}</x-k.badge>
                                        <div class="k-text-subtle" style="font-size:var(--k-text-sm)">
                                            {{ translate($webhook->disabled_reason ?? 'webhook_disabled') }}
                                        </div>
                                    @elseif ($webhook->last_success_at === null && $webhook->last_failure_at === null)
                                        {{-- Nothing has been sent to it. Not a green tick it has
                                             not earned. --}}
                                        <x-k.badge>{{ translate('never_called') }}</x-k.badge>
                                    @elseif ($webhook->consecutive_failures > 0)
                                        <x-k.badge tone="warning">
                                            {{ translate('failing') }} ({{ $webhook->consecutive_failures }})
                                        </x-k.badge>
                                    @else
                                        <x-k.badge tone="success">{{ translate('healthy') }}</x-k.badge>
                                    @endif
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        @if ($webhook->status !== 'disabled')
                                            <form method="POST"
                                                  action="{{ route('admin.marketplace.seller-operations.disable-webhook') }}"
                                                  onsubmit="return confirm('{{ translate('the_platform_will_stop_calling_this_endpoint') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $webhook->id }}">
                                                <x-k.button variant="danger" size="sm" type="submit">
                                                    {{ translate('disable') }}
                                                </x-k.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $webhooks->links() }}</div>
            @endif
        </x-k.card>
    </div>
@endsection
