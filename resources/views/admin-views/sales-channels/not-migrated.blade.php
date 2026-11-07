@extends('layouts.admin.app')

@section('title', translate('Sales_Channels'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Sales_Channels')" />

        <x-k.card :title="translate('sales_channels_not_migrated')">
            <p class="fs-12 text-muted">{{ translate('sales_channels_not_migrated_help') }}</p>
            <pre class="bg-light rounded p-2 mb-0 fs-12">php artisan migrate @foreach ($migrations as $migration)\
    --path=database/migrations/{{ $migration }}.php @endforeach</pre>
        </x-k.card>
    </div>
@endsection
