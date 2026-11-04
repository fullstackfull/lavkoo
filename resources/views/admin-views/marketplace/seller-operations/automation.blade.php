@extends('layouts.admin.app')

@section('title', translate('seller_automation'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);

    $ruleTone = ['active' => 'success', 'paused' => 'neutral', 'suspended' => 'danger'];
    $actionTone = ['applied' => 'success', 'failed' => 'danger', 'skipped' => 'neutral'];
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('seller_automation')"
                         :subtitle="translate('rules_that_change_catalogues_unattended_and_what_they_have_done')" />

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <x-k.card class="mb-4" :title="translate('rules')" :padded="false">
            @if ($rules && $rules->total() > 0)
                <x-slot:actions>
                    <span class="k-tab__count k-num">{{ $rules->total() }}</span>
                </x-slot:actions>
            @endif

            @if ($rules === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($rules->isEmpty())
                <x-k.empty icon="settings" :title="translate('no_seller_has_written_a_rule_yet')"
                           :text="translate('a_rule_does_nothing_until_a_seller_creates_one')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('rule') }}</th>
                                <th>{{ translate('what_it_does') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="k-table__num">{{ translate('runs') }}</th>
                                <th class="k-table__num">{{ translate('changes') }}</th>
                                <th>{{ translate('last_run') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td>{{ $shopName($rule->seller_id) }}</td>
                                <td>{{ $rule->name }}</td>
                                {{-- Trigger and action read as one sentence: two columns of keys
                                     make a reader assemble the meaning themselves. --}}
                                <td class="k-text-muted">{{ $rule->trigger }} → {{ $rule->action }}</td>
                                <td>
                                    <x-k.badge :tone="$ruleTone[$rule->status] ?? 'neutral'">
                                        {{ translate($rule->status) }}
                                    </x-k.badge>
                                    @if ($rule->suspension_reason)
                                        <div class="k-text-subtle" style="font-size:var(--k-text-sm)">
                                            {{ translate($rule->suspension_reason) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="k-table__num k-num">{{ $rule->run_count }}</td>
                                <td class="k-table__num k-num">{{ $rule->applied_count }}</td>
                                <td class="k-text-muted">{{ $rule->last_run_at ?? '—' }}</td>
                                <td>
                                    <div class="k-table__actions">
                                        @if ($rule->status !== 'suspended')
                                            <form method="POST"
                                                  action="{{ route('admin.marketplace.seller-operations.suspend-rule') }}"
                                                  onsubmit="return confirm('{{ translate('stop_this_rule_only_the_marketplace_can_start_it_again') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $rule->id }}">
                                                <x-k.button variant="danger" size="sm" type="submit">
                                                    {{ translate('stop') }}
                                                </x-k.button>
                                            </form>
                                        @elseif ($rule->suspended_by === \App\Models\SellerAutomationRule::SUSPENDED_BY_MARKETPLACE)
                                            {{-- Only shown where the seller is the one who cannot
                                                 lift it: a breaker suspension is theirs to clear. --}}
                                            <form method="POST"
                                                  action="{{ route('admin.marketplace.seller-operations.release-rule') }}">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $rule->id }}">
                                                <x-k.button variant="secondary" size="sm" type="submit">
                                                    {{ translate('allow_again') }}
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
                <div class="k-pager">{{ $rules->links() }}</div>
            @endif
        </x-k.card>

        <x-k.card :title="translate('what_automation_has_changed')" :padded="false">
            <x-slot:actions>
                <span class="k-text-subtle">{{ translate('the_answer_to_who_changed_this_when_it_is_not_a_person') }}</span>
            </x-slot:actions>

            @if ($activity === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($activity->isEmpty())
                <x-k.empty icon="clock" :title="translate('automation_has_not_changed_anything_yet')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table k-table--compact">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('action') }}</th>
                                <th>{{ translate('subject') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('change') }}</th>
                                <th>{{ translate('date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($activity as $row)
                            <tr>
                                <td>{{ $shopName($row->seller_id) }}</td>
                                <td class="k-text-muted">{{ $row->action }}</td>
                                <td>{{ $row->subject_label ?? ($row->subject_type . ' #' . $row->subject_id) }}</td>
                                <td>
                                    <x-k.badge :tone="$actionTone[$row->status] ?? 'neutral'">
                                        {{ translate($row->status) }}
                                    </x-k.badge>
                                    @if ($row->reason)
                                        <div class="k-text-subtle" style="font-size:var(--k-text-sm)">
                                            {{ translate($row->reason) }}
                                        </div>
                                    @endif
                                </td>
                                {{-- Before and after on one line, which is the only form in which
                                     a reader can see what actually moved. --}}
                                <td class="k-num k-text-muted">
                                    @if ($row->before || $row->after)
                                        {{ json_encode($row->before) }} → {{ json_encode($row->after) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="k-text-muted">{{ $row->created_at }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $activity->links() }}</div>
            @endif
        </x-k.card>
    </div>
@endsection
