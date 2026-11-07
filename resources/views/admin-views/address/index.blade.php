@extends('layouts.admin.app')

@section('title', translate('address_system'))

@section('content')
    @php
        $mode = $report['mode'];
        $catalog = $report['catalog'];
        $addresses = $report['addresses'];
        $import = $report['last_import'];
        $modeTone = ['off' => 'neutral', 'soft' => 'info', 'strict' => 'success'];
        $deliveryZoneUrl = Route::has('admin.business-settings.delivery-zone.index') ? route('admin.business-settings.delivery-zone.index') : null;
        $syncTone = ['full' => 'success', 'partial' => 'warning', 'failed' => 'danger'];
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" :subtitle="translate('address_system_subtitle')" />
        @include('admin-views.address.partials._tabs', ['active' => 'overview'])

        @if ($report['zip_restriction'] && $mode['configured'] !== 'off')
            <div class="alert alert-warning fs-12" role="alert">
                {{ translate('address_zip_conflict_active') }}
                @if ($deliveryZoneUrl)<a class="alert-link" href="{{ $deliveryZoneUrl }}">{{ translate('address_open_delivery_restriction') }}</a>@endif
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-lg-7">
                <x-k.card :title="translate('address_mode')" class="h-100">
                    <div class="d-flex flex-wrap gap-3 mb-3 fs-12">
                        <span>{{ translate('address_mode_in_force') }}: <x-k.badge :tone="$modeTone[$mode['in_force']]">{{ translate('address_mode_' . $mode['in_force']) }}</x-k.badge></span>
                        <span>{{ translate('address_mode_chosen') }}: <x-k.badge :tone="$modeTone[$mode['configured']]">{{ translate('address_mode_' . $mode['configured']) }}</x-k.badge></span>
                    </div>

                    @if ($mode['configured'] !== $mode['in_force'] && !$report['zip_restriction'])
                        <div class="alert alert-warning fs-12">{{ translate('address_mode_held_off_no_cities') }}</div>
                    @endif

                    @if ($blocked !== [])
                        <div class="alert alert-warning fs-12" role="alert">
                            <ul class="mb-0 ps-3">
                                @foreach ($blocked as $problem)
                                    <li>{{ translate($problem) }}</li>
                                @endforeach
                            </ul>
                            @if ($report['zip_restriction'] && $deliveryZoneUrl)
                                <a class="alert-link d-inline-block mt-2" href="{{ $deliveryZoneUrl }}">{{ translate('address_open_delivery_restriction') }}</a>
                            @endif
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.address-system.mode.update') }}">@csrf
                        @foreach ($modes as $option)
                            <label class="d-flex gap-2 align-items-start border rounded p-2 mb-2">
                                <input class="form-check-input mt-1" type="radio" name="mode" value="{{ $option }}"
                                       @checked($mode['configured'] === $option) @disabled($option !== 'off' && $blocked !== [])>
                                <span>
                                    <strong>{{ translate('address_mode_' . $option) }}</strong>
                                    <span class="d-block fs-12 text-muted">{{ translate('address_mode_' . $option . '_help') }}</span>
                                </span>
                            </label>
                        @endforeach
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('address_mode_save') }}</button>
                    </form>
                </x-k.card>
            </div>

            <div class="col-lg-5">
                <x-k.card :title="translate('address_lists')" class="h-100">
                    <div class="k-stats">
                        <x-k.stat :label="translate('address_regions')" :value="number_format($catalog['regions_active'])" :caption="translate('address_switched_off_count') . ': ' . number_format($catalog['regions'] - $catalog['regions_active'])" :href="route('admin.address-system.regions.index')" />
                        <x-k.stat :label="translate('address_cities')" :value="number_format($catalog['cities_active'])" :caption="translate('address_switched_off_count') . ': ' . number_format($catalog['cities'] - $catalog['cities_active'])" :href="route('admin.address-system.cities.index')" />
                        <x-k.stat :label="translate('address_aliases')" :value="number_format($catalog['aliases'])" />
                    </div>
                </x-k.card>
            </div>
        </div>

        <x-k.card :title="translate('address_saved_saudi_addresses')" class="mb-3">
            <x-slot:actions>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.address-system.legacy.index') }}">{{ translate('address_open_legacy_review') }}</a>
            </x-slot:actions>
            <div class="k-stats">
                <x-k.stat :label="translate('address_saudi_total')" :value="number_format($addresses['saudi'])" />
                <x-k.stat :label="translate('address_unified_count')" :value="number_format($addresses['unified'])" />
                <x-k.stat :label="translate('address_legacy_matched_count')" :value="$addresses['legacy_matched'] === null ? '—' : number_format($addresses['legacy_matched'])" />
                <x-k.stat :label="translate('address_legacy_unmatched_count')" :value="$addresses['legacy_unmatched'] === null ? '—' : number_format($addresses['legacy_unmatched'])" />
                <x-k.stat :label="translate('address_needing_completion')" :value="number_format($addresses['needing_completion'])" />
                <x-k.stat :label="translate('address_without_zip')" :value="number_format($addresses['without_zip'])" />
            </div>
            @if ($addresses['legacy_matched'] === null)
                <p class="fs-12 text-muted mt-2 mb-0">{{ translate('address_legacy_not_counted') }}</p>
            @endif
        </x-k.card>

        <div class="row g-3">
            <div class="col-lg-5">
                <x-k.card :title="translate('address_data')" class="h-100">
                    <x-slot:actions>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.address-system.import.index') }}">{{ translate('import') }}</a>
                    </x-slot:actions>
                    @if ($import === null)
                        <p class="fs-12 text-muted mb-0">{{ translate('address_data_none') }}</p>
                    @else
                        <div class="pair-list">
                            <div><span class="key">{{ translate('source') }}</span><span>:</span><span class="value" dir="ltr">{{ $import['source'] ?? '—' }}</span></div>
                            <div><span class="key">{{ translate('address_cities') }}</span><span>:</span><span class="value">{{ number_format($import['cities']) }}</span></div>
                            <div><span class="key">{{ translate('address_data_snapshot') }}</span><span>:</span><span class="value">{{ $import['snapshot_at']?->toDateString() ?? '—' }}</span></div>
                            <div><span class="key">{{ translate('address_data_loaded') }}</span><span>:</span><span class="value">{{ $import['loaded_at']?->toDateString() ?? '—' }}</span></div>
                        </div>
                    @endif
                </x-k.card>
            </div>

            <div class="col-lg-7">
                <x-k.card :title="translate('address_provider_mappings')" :padded="false" class="h-100">
                    @if (! $report['mappings_ready'])
                        <p class="fs-12 text-muted p-3 mb-0">{{ translate('address_mappings_not_ready') }}</p>
                    @elseif ($report['providers'] === [])
                        <p class="fs-12 text-muted p-3 mb-0">{{ translate('address_no_provider_mappings') }}</p>
                    @else
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{ translate('address_provider') }}</th>
                                    <th>{{ translate('address_mapping_approved') }}</th>
                                    <th>{{ translate('address_mapping_pending') }}</th>
                                    <th>{{ translate('address_mapping_rejected') }}</th>
                                    <th>{{ translate('address_mapping_retired') }}</th>
                                    <th>{{ translate('address_cities_without_mapping') }}</th>
                                    <th>{{ translate('address_last_city_sync') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($report['providers'] as $provider)
                                    <tr>
                                        <td>{{ $provider['label'] }}</td>
                                        <td>{{ number_format($provider['approved']) }}</td>
                                        <td>{{ number_format($provider['pending']) }}</td>
                                        <td>{{ number_format($provider['rejected']) }}</td>
                                        <td>{{ number_format($provider['retired']) }}</td>
                                        <td>{{ number_format($provider['cities_without_approved']) }}</td>
                                        <td>
                                            @if ($provider['last_sync'])
                                                <x-k.badge :tone="$syncTone[$provider['last_sync']['verdict']] ?? 'neutral'">{{ translate('address_sync_' . $provider['last_sync']['verdict']) }}</x-k.badge>
                                                <div class="fs-10 text-muted">{{ $provider['last_sync']['at']?->diffForHumans() }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if (Route::has('admin.fulfillment-providers.torod.matching'))
                        <x-slot:footer>
                            <a href="{{ route('admin.fulfillment-providers.torod.matching') }}">{{ translate('address_open_provider_matching') }}</a>
                        </x-slot:footer>
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
