@extends('layouts.admin.app')

@section('title', translate('address_system'))

@section('content')
    @php
        $pending = array_keys(array_filter($migrations, static fn (bool $done): bool => ! $done));
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" />

        <x-k.card :title="translate('address_not_migrated')">
            <p class="fs-12 text-muted">{{ translate('address_not_migrated_help') }}</p>
            <ul class="list-unstyled fs-12 d-flex flex-column gap-1">
                @foreach ($migrations as $migration => $done)
                    <li class="d-flex gap-2 align-items-center">
                        <x-k.badge :tone="$done ? 'success' : 'warning'">{{ $done ? translate('address_migration_done') : translate('address_migration_pending') }}</x-k.badge>
                        <code dir="ltr">{{ $migration }}</code>
                    </li>
                @endforeach
            </ul>
            @if ($pending !== [])
                <pre class="bg-light rounded p-2 mb-0 fs-12" dir="ltr">php artisan migrate @foreach ($pending as $migration)\
    --path=database/migrations/{{ $migration }}.php @endforeach</pre>
            @endif
        </x-k.card>
    </div>
@endsection
