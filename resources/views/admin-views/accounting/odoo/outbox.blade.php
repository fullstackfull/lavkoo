@extends('layouts.admin.app')

@section('title', translate('Odoo_Posting_Outbox'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Odoo_Posting_Outbox')" :subtitle="translate('odoo_outbox_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.index') }}">{{ translate('Odoo_Accounting') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.mapping.index') }}">{{ translate('Odoo_Account_Mapping') }}</a>
                <form method="POST" action="{{ route('admin.accounting.odoo.outbox.drain') }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary btn-sm" type="submit" @disabled($connection === null)>{{ translate('odoo_work_a_batch') }}</button>
                </form>
            </x-slot:actions>
        </x-k.page-header>

        <div class="row g-2">
            @foreach ($counts as $stateKey => $total)
                <div class="col-6 col-md-4 col-xl-2">
                    <a class="text-decoration-none" href="{{ route('admin.accounting.odoo.outbox.index', ['state' => $stateKey]) }}">
                        <x-k.stat :label="translate('odoo_posting_state_' . $stateKey)" :value="$total" />
                    </a>
                </div>
            @endforeach
        </div>

        @if (($counts['needs_review'] ?? 0) > 0)
            <p class="small text-warning mt-2">{{ translate('odoo_needs_review_explainer') }}</p>
        @endif

        <x-k.card class="mt-3" :padded="false">
            @if ($rows->isEmpty())
                <div class="p-3">
                    <x-k.empty icon="file-text" :title="translate('odoo_nothing_here')" :text="translate('odoo_nothing_here_help')" />
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ translate('odoo_reference') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('odoo_entry') }}</th>
                                <th>{{ translate('attempts') }}</th>
                                <th>{{ translate('reason') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class(['table-warning' => $row->state === \App\Models\OdooPosting::NEEDS_REVIEW])>
                                    <td>
                                        <code class="small">{{ $row->odoo_ref }}</code>
                                        <div class="small text-muted">{{ $row->source_type }} #{{ $row->source_id }}</div>
                                    </td>
                                    <td>
                                        <x-k.badge :tone="match ($row->state) {
                                            \App\Models\OdooPosting::POSTED => 'success',
                                            \App\Models\OdooPosting::NEEDS_REVIEW => 'warning',
                                            \App\Models\OdooPosting::MAY_HAVE_POSTED => 'warning',
                                            \App\Models\OdooPosting::DEAD => 'danger',
                                            default => 'neutral',
                                        }">{{ translate('odoo_posting_state_' . $row->state) }}</x-k.badge>
                                    </td>
                                    <td class="small">{{ $row->odoo_move_id ?: '—' }}</td>
                                    <td class="small">{{ $row->attempts }}</td>
                                    <td class="small">
                                        {{-- A failure code is shown as it came: handing an unknown code to translate() would write it into the language files. --}}
                                        @if ($row->failure_code)<code>{{ $row->failure_code }}</code>@endif
                                        @if ($row->failure_detail)<div class="text-muted">{{ \Illuminate\Support\Str::limit($row->failure_detail, 160) }}</div>@endif
                                    </td>
                                    <td class="text-end">
                                        @if ($row->state === \App\Models\OdooPosting::NEEDS_REVIEW)
                                            {{-- The only two ways a person may end this state. Neither invents a figure. --}}
                                            <form method="POST" action="{{ route('admin.accounting.odoo.outbox.found', $row->id) }}" class="k-row justify-content-end mb-1">
                                                @csrf
                                                <input class="form-control form-control-sm" style="max-width: 7rem" type="number" min="1" name="odoo_move_id"
                                                       placeholder="{{ translate('odoo_entry_id') }}" required>
                                                <input class="form-control form-control-sm" style="max-width: 14rem" type="text" name="reason"
                                                       placeholder="{{ translate('odoo_what_you_found') }}" required>
                                                <button class="btn btn-outline-success btn-sm" type="submit">{{ translate('odoo_odoo_has_it') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.accounting.odoo.outbox.closed', $row->id) }}" class="k-row justify-content-end">
                                                @csrf
                                                <input class="form-control form-control-sm" style="max-width: 14rem" type="text" name="reason"
                                                       placeholder="{{ translate('odoo_why_it_is_not_there') }}" required>
                                                <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('odoo_it_is_not_there') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-k.card>

        <div class="mt-3">{{ $rows->links() }}</div>
    </div>
@endsection
