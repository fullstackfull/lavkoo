@extends('layouts.admin.app')

@section('title', translate('address_import_title'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" :subtitle="translate('address_import_subtitle')" />
        @include('admin-views.address.partials._tabs', ['active' => 'import'])

        <x-k.card :title="translate('address_import_files')" :padded="false" class="mb-3">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                    <tr>
                        <th>{{ translate('address_import_file') }}</th>
                        <th>{{ translate('source') }}</th>
                        <th>{{ translate('address_data_snapshot') }}</th>
                        <th>{{ translate('address_import_rows') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($files as $kind => $file)
                        <tr>
                            <td><strong>{{ translate('address_' . $kind) }}</strong><div class="fs-10 text-muted" dir="ltr">{{ $file['file'] }}</div></td>
                            <td dir="ltr">{{ $file['source'] ?? '—' }}</td>
                            <td>{{ $file['snapshot_date'] ?? '—' }}</td>
                            <td>{{ $file['readable'] ? number_format($file['rows']) : translate('address_import_unreadable') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-slot:footer>
                <form method="POST" action="{{ route('admin.address-system.import.preview') }}" class="d-flex flex-wrap align-items-center gap-3">@csrf
                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('address_import_preview') }}</button>
                    <span class="fs-12 text-muted">{{ translate('address_import_preview_help') }}</span>
                </form>
            </x-slot:footer>
        </x-k.card>

        @if ($report)
            <x-k.card :title="$report['applied'] ? translate('address_import_result_applied') : translate('address_import_result_preview')" :padded="false" class="mb-3">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th></th>
                            @foreach (['created', 'updated', 'unchanged', 'skipped', 'not_in_file'] as $column)
                                <th>{{ translate('address_import_' . $column) }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach (['regions', 'cities', 'aliases'] as $kind)
                            <tr>
                                <td><strong>{{ translate('address_' . $kind) }}</strong></td>
                                @foreach (['created', 'updated', 'unchanged', 'skipped', 'not_in_file'] as $column)
                                    <td>{{ number_format($report[$kind][$column]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($report['problems'] !== [])
                    <x-slot:footer>
                        <div class="fs-12">
                            <strong>{{ translate('address_import_problems') }}</strong>
                            <ul class="mb-0 ps-3">
                                @foreach (array_slice($report['problems'], 0, 20) as $problem)
                                    <li dir="ltr">{{ $problem }}</li>
                                @endforeach
                            </ul>
                            @if (count($report['problems']) > 20)<div class="text-muted">+ {{ count($report['problems']) - 20 }}</div>@endif
                        </div>
                    </x-slot:footer>
                @endif
            </x-k.card>

            @unless ($report['applied'])
                <x-k.card :title="translate('address_import_apply')">
                    <form method="POST" action="{{ route('admin.address-system.import.apply') }}" class="d-flex flex-column gap-3">@csrf
                        <label class="d-flex gap-2 align-items-start fs-12">
                            <input class="form-check-input mt-1" type="checkbox" name="confirm" value="1" required>
                            <span>{{ translate('address_import_confirm') }}</span>
                        </label>
                        <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('address_import_apply') }}</button></div>
                    </form>
                </x-k.card>
            @endunless
        @endif
    </div>
@endsection
