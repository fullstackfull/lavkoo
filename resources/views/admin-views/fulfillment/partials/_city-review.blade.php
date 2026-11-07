{{-- The Torod Point city review: how complete the cached city list is, our cities orders are waiting on with Torod's candidates side by side, the approved matches, and a manual match. Expects $cityReview (App\Services\Fulfillment\ProviderCityReview::overview). --}}
@php
    $run = $cityReview['last_run'];
    $verdictTone = ['full' => 'success', 'partial' => 'warning', 'failed' => 'danger'];
    $regionTone = ['confirmed' => 'success', 'unknown' => 'neutral', 'unplaceable' => 'warning'];
    $statusTone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'retired' => 'neutral'];
    $flagLabel = static fn (?bool $flag): string => $flag === null ? '—' : ($flag ? translate('yes') : translate('no'));
@endphp

<x-k.card :title="translate('city_directory')" class="mb-3">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.fulfillment-providers.sync', ['torod-point', 'cities']) }}">@csrf
            <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('sync_cities_now') }}</button></form>
    </x-slot:actions>

    @if ($run === null)
        <p class="text-muted fs-12 mb-0">{{ translate('city_sync_never_run') }}</p>
    @else
        <div class="d-flex flex-wrap align-items-center gap-2 fs-12">
            <x-k.badge :tone="$verdictTone[$run->verdict] ?? 'neutral'">{{ translate('city_sync_' . $run->verdict) }}</x-k.badge>
            <span>{{ translate('city_sync_cities_read') }}: {{ $run->distinct_ids }}</span>
            @if ($run->expected_total !== null)<span>· {{ translate('city_sync_provider_total') }}: {{ $run->expected_total }}</span>@endif
            <span>· {{ translate('requests') }}: {{ $run->pages }}</span>
            <span class="text-muted">· {{ $run->finished_at?->diffForHumans() }}</span>
        </div>
        @if ($run->reason)<div class="fs-12 mt-1">{{ translate('reason') }}: {{ $run->reason }}</div>@endif
        @if ($run->verdict !== 'full')<div class="alert alert-warning fs-12 mt-2 mb-0">{{ translate('city_sync_incomplete_help') }}</div>@endif
    @endif

    <div class="fs-12 text-muted mt-2">
        {{ translate('city_directory_cached') }}: {{ $cityReview['directory']['total'] }}
        · {{ translate('city_directory_selected') }}: {{ $cityReview['directory']['selected'] }}
        · {{ translate('city_directory_missing') }}: {{ $cityReview['directory']['missing'] }}
    </div>

    @if ($cityReview['runs']->count() > 1)
        <div class="k-table-wrap mt-2">
            <table class="k-table">
                <thead><tr><th>{{ translate('city_sync_run') }}</th><th>{{ translate('status') }}</th><th>{{ translate('city_sync_cities_read') }}</th><th>{{ translate('requests') }}</th><th>{{ translate('reason') }}</th></tr></thead>
                <tbody>
                @foreach ($cityReview['runs'] as $past)
                    <tr>
                        <td>{{ $past->finished_at?->diffForHumans() }}</td>
                        <td><x-k.badge :tone="$verdictTone[$past->verdict] ?? 'neutral'">{{ translate('city_sync_' . $past->verdict) }}</x-k.badge></td>
                        <td>{{ $past->distinct_ids }}</td>
                        <td>{{ $past->pages }}</td>
                        <td class="fs-12">{{ $past->reason }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-k.card>

<x-k.card :title="translate('cities_waiting_for_a_decision')" :padded="false" class="mb-3">
    @forelse ($cityReview['cities'] as $block)
        @php($city = $block['city'])
        <div class="p-3 border-bottom">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <strong>{{ $city->name_ar }} / {{ $city->name_en }}</strong>
                    <span class="text-muted fs-12">· {{ $city->region?->name_ar }} / {{ $city->region?->name_en }} · #{{ $city->id }}</span>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        @if ($block['orders'] > 0)<x-k.badge tone="warning">{{ translate('city_orders_waiting') }}: {{ $block['orders'] }}</x-k.badge>@endif
                        @if ($block['conflict'])<x-k.badge tone="danger">{{ translate('city_has_several_candidates') }}</x-k.badge>@endif
                        @if ($block['code'])<x-k.badge>{{ translate('city_hold_code_' . $block['code']) }}</x-k.badge>@endif
                    </div>
                    @if ($block['shipments'] !== [])
                        <div class="fs-12 mt-1">
                            @foreach ($block['shipments'] as $shipment)
                                <a href="{{ route('admin.fulfillment-providers.fulfillments.show', $shipment['id']) }}">{{ $shipment['reference'] }}</a>@unless ($loop->last), @endunless
                            @endforeach
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.fulfillment-providers.torod.cities.discover') }}">@csrf
                    <input type="hidden" name="address_city_id" value="{{ $city->id }}">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('city_find_candidates_at_torod') }}</button>
                </form>
            </div>

            @if ($block['candidates'] === [])
                <p class="text-muted fs-12 mt-2 mb-0">{{ translate('city_no_candidates_known') }}</p>
            @else
                <div class="k-table-wrap mt-2">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('torod_city_id') }}</th><th>{{ translate('name') }}</th><th>{{ translate('city_torod_region') }}</th>
                            <th>{{ translate('selected') }}</th><th>{{ translate('prefix') }}</th><th>{{ translate('city_region_check') }}</th><th>{{ translate('status') }}</th><th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($block['candidates'] as $entry)
                            @php($candidate = $entry['candidate'])
                            @php($torodCity = $candidate->city)
                            <tr>
                                <td>{{ $torodCity->external_id }}</td>
                                <td>{{ $torodCity->name_ar }} / {{ $torodCity->name_en }}</td>
                                <td>{{ $torodCity->region_external_id }}<div class="fs-10 text-muted">{{ $torodCity->region_name_ar }} / {{ $torodCity->region_name_en }}</div></td>
                                <td>{{ $flagLabel($torodCity->is_selected) }}</td>
                                <td>{{ $torodCity->prefix }}</td>
                                <td><x-k.badge :tone="$regionTone[$candidate->region] ?? 'neutral'">{{ translate('city_region_' . $candidate->region) }}</x-k.badge></td>
                                <td>@if ($entry['status'])<x-k.badge :tone="$statusTone[$entry['status']] ?? 'neutral'">{{ translate('mapping_status_' . $entry['status']) }}</x-k.badge>@endif</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        @if ($entry['status'] !== 'approved')
                                            <form method="POST" action="{{ route('admin.fulfillment-providers.torod.cities.approve') }}">@csrf
                                                <input type="hidden" name="address_city_id" value="{{ $city->id }}">
                                                <input type="hidden" name="external_city_id" value="{{ $torodCity->external_id }}">
                                                @if ($block['approved'])<input type="hidden" name="replacing" value="{{ $block['approved']->id }}">@endif
                                                <button class="btn btn-primary btn-sm" type="submit">{{ $block['approved'] ? translate('city_replace_with_this') : translate('approve') }}</button>
                                            </form>
                                            @if ($entry['status'] !== 'rejected')
                                                <form method="POST" action="{{ route('admin.fulfillment-providers.torod.cities.reject') }}">@csrf
                                                    <input type="hidden" name="address_city_id" value="{{ $city->id }}">
                                                    <input type="hidden" name="external_city_id" value="{{ $torodCity->external_id }}">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('reject') }}</button>
                                                </form>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <x-k.empty icon="search" :title="translate('city_nothing_waiting')" :text="translate('city_nothing_waiting_help')" />
    @endforelse
</x-k.card>

<x-k.card :title="translate('city_approved_matches')" :padded="false" class="mb-3">
    <div class="k-table-wrap">
        <table class="k-table">
            <thead><tr><th>{{ translate('city') }}</th><th>{{ translate('torod_city_id') }}</th><th>{{ translate('city_torod_region') }}</th><th>{{ translate('method') }}</th><th>{{ translate('city_approved') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($cityReview['approved'] as $row)
                @php($mapping = $row['mapping'])
                @php($decision = collect($mapping->evidence['decisions'] ?? [])->last())
                <tr>
                    <td>{{ $row['city']?->name_ar }} / {{ $row['city']?->name_en }}<div class="fs-10 text-muted">#{{ $mapping->address_city_id }} · {{ $row['city']?->region?->name_ar }}</div></td>
                    <td>{{ $mapping->external_city_id }}<div class="fs-10 text-muted">{{ $row['provider_city']?->name_ar }} / {{ $row['provider_city']?->name_en }}</div></td>
                    <td>{{ $mapping->external_region_id }}<div class="fs-10 text-muted">{{ $row['provider_city']?->region_name_ar }} / {{ $row['provider_city']?->region_name_en }}</div></td>
                    <td>{{ in_array($mapping->method, ['manual', 'auto_unique'], true) ? translate('city_method_' . $mapping->method) : $mapping->method }}</td>
                    <td>{{ $mapping->approved_at?->diffForHumans() }}@if (! empty($decision['by']['name']))<div class="fs-10 text-muted">{{ $decision['by']['name'] }}</div>@endif</td>
                    <td>
                        <form method="POST" action="{{ route('admin.fulfillment-providers.torod.cities.retire', $mapping->id) }}" onsubmit="return confirm(@js(translate('are_you_sure')))">@csrf
                            <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('retire') }}</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">{{ translate('no_mappings_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-k.card>

<x-k.card :title="translate('city_manual_match')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('city_manual_match_help') }}</p>
    <form method="POST" action="{{ route('admin.fulfillment-providers.torod.cities.approve') }}" id="manual-city-match" class="row g-3">@csrf
        <input type="hidden" name="address_city_id" id="manual-city-id">
        <input type="hidden" name="external_city_id" id="manual-provider-city-id">
        <div class="col-lg-5">
            <label class="form-label fs-12" for="manual-city-search">{{ translate('city_search_our_cities') }}</label>
            <input class="form-control form-control-sm" id="manual-city-search" autocomplete="off" data-url="{{ route('admin.fulfillment-providers.torod.cities.internal-search') }}">
            <div class="list-group list-group-flush fs-12" id="manual-city-results"></div>
            <div class="fs-12 fw-semibold mt-1" id="manual-city-chosen"></div>
        </div>
        <div class="col-lg-5">
            <label class="form-label fs-12" for="manual-provider-search">{{ translate('search_torod_cities') }}</label>
            <input class="form-control form-control-sm" id="manual-provider-search" autocomplete="off" data-url="{{ route('admin.fulfillment-providers.torod.cities.search') }}">
            <div class="list-group list-group-flush fs-12" id="manual-provider-results"></div>
            <div class="fs-12 fw-semibold mt-1" id="manual-provider-chosen"></div>
        </div>
        <div class="col-lg-2 d-flex align-items-end">
            <button class="btn btn-primary btn-sm w-100" type="submit" id="manual-city-submit" disabled>{{ translate('approve') }}</button>
        </div>
    </form>
</x-k.card>
