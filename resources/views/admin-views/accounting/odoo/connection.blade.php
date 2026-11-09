@extends('layouts.admin.app')

@section('title', translate('Odoo_Accounting'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Odoo_Accounting')" :subtitle="translate('odoo_accounting_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.mapping.index') }}">{{ translate('Odoo_Account_Mapping') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.outbox.index') }}">{{ translate('Odoo_Posting_Outbox') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.reconciliation.index') }}">{{ translate('Odoo_Three_Way_Reconciliation') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        {{-- The four switches, shown and not operable: they are environment settings, and a button here would be lying. --}}
        <x-k.card :title="translate('odoo_switches')">
            <p class="text-muted small">{{ translate('odoo_switches_help') }}</p>
            <div class="row g-2">
                @foreach ($switches as $switch)
                    <div class="col-md-6 col-xl-3">
                        <div class="k-row justify-content-between border rounded p-2">
                            <span>{{ translate($switch['key']) }}</span>
                            <x-k.badge :tone="$switch['on'] ? 'success' : 'neutral'">{{ $switch['on'] ? translate('on') : translate('off') }}</x-k.badge>
                        </div>
                        <code class="small text-muted">{{ $switch['env'] }}</code>
                    </div>
                @endforeach
            </div>
        </x-k.card>

        <div class="row g-3 mt-1">
            <div class="col-xl-7">
                <x-k.card :title="translate('odoo_where_the_instance_is')">
                    <form method="POST" action="{{ route('admin.accounting.odoo.settings') }}">
                        @csrf
                        <x-k.field :label="translate('odoo_base_url')" name="base_url" :hint="translate('odoo_base_url_help')" required>
                            <input class="form-control" type="url" id="base_url" name="base_url" required
                                   value="{{ old('base_url', $connection?->setting('base_url', '')) }}" placeholder="https://books.example.com">
                        </x-k.field>
                        <x-k.field :label="translate('odoo_database')" name="database" :hint="translate('odoo_database_help')">
                            <input class="form-control" type="text" id="database" name="database"
                                   value="{{ old('database', $connection?->setting('database', '')) }}">
                        </x-k.field>
                        <x-k.field :label="translate('odoo_login')" name="login" :hint="translate('odoo_login_help')">
                            <input class="form-control" type="text" id="login" name="login"
                                   value="{{ old('login', $connection?->setting('login', '')) }}">
                        </x-k.field>
                        <x-k.field :label="translate('odoo_company_id')" name="company_id" :hint="translate('odoo_company_id_help')">
                            <input class="form-control" type="number" min="1" id="company_id" name="company_id"
                                   value="{{ old('company_id', $connection?->setting('company_id', '')) }}">
                        </x-k.field>
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('save') }}</button>
                    </form>
                </x-k.card>

                <x-k.card :title="translate('odoo_api_key')" class="mt-3">
                    <p class="text-muted small">{{ translate('odoo_api_key_help') }}</p>
                    <form method="POST" action="{{ route('admin.accounting.odoo.key') }}">
                        @csrf
                        <x-k.field :label="translate('odoo_api_key')" name="api_key" :hint="translate('odoo_api_key_write_only')" required>
                            {{-- Blank on every load: a stored secret is never sent back to a screen. --}}
                            <input class="form-control" type="password" id="api_key" name="api_key" autocomplete="new-password" required>
                        </x-k.field>
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate($hasKey ? 'odoo_replace_the_key' : 'save') }}</button>
                        @if ($hasKey)
                            <span class="small text-muted ms-2">{{ translate('odoo_a_key_is_stored') }}</span>
                        @endif
                    </form>
                </x-k.card>
            </div>

            <div class="col-xl-5">
                <x-k.card :title="translate('odoo_what_the_instance_turned_out_to_be')">
                    <dl class="row mb-0 small">
                        <dt class="col-6">{{ translate('odoo_transport') }}</dt>
                        <dd class="col-6">{{ translate($transport->labelKey()) }}</dd>
                        <dt class="col-6">{{ translate('odoo_server_version') }}</dt>
                        <dd class="col-6">{{ $serverVersion ?: translate('not_read_yet') }}</dd>
                    </dl>

                    @if ($transport->isDeprecatedUpstream())
                        <p class="small text-warning mt-2 mb-0">{{ translate('odoo_transport_deprecated_note') }}</p>
                    @endif

                    <form method="POST" action="{{ route('admin.accounting.odoo.test') }}" class="mt-3">
                        @csrf
                        <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('odoo_test_the_connection') }}</button>
                        <span class="small text-muted ms-2">{{ translate('odoo_test_reads_only') }}</span>
                    </form>
                </x-k.card>

                @if ($readiness !== [])
                    <x-k.card :title="translate('odoo_not_ready_for')" class="mt-3">
                        <ul class="mb-0 small">
                            @foreach ($readiness as $code)
                                {{-- Shown as it came: a code this version does not know must not be handed to translate(), which would write it into the language files. --}}
                                <li><code>{{ $code }}</code></li>
                            @endforeach
                        </ul>
                    </x-k.card>
                @endif
            </div>
        </div>
    </div>
@endsection
