@extends('layouts.admin.app')

@section('title', translate('Fulfillment_Providers'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Fulfillment_Providers')" />

        <x-k.card :title="translate('fulfillment_not_migrated')">
            <p class="fs-12 text-muted">{{ translate('fulfillment_not_migrated_help') }}</p>
            <pre class="bg-light rounded p-2 mb-0 fs-12">php artisan migrate @foreach ($migrations as $migration)\
    --path=database/migrations/{{ $migration }}.php @endforeach</pre>
        </x-k.card>
    </div>
@endsection
