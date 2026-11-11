@extends('layouts.admin.app')

@section('title', translate('Odoo_Accounting'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Odoo_Accounting')" :subtitle="translate('odoo_accounting_subtitle')" />

        <x-k.card>
            <x-k.empty icon="database" :title="translate('odoo_migration_not_run')" :text="translate('odoo_migration_not_run_help')" />
            <pre class="mt-3 mb-0"><code>php artisan migrate --path=database/migrations/{{ $migration }}.php</code></pre>
        </x-k.card>
    </div>
@endsection
