{{--
    Storefront search: how much of the catalogue it can actually find.

    Search does not read the products table. It reads a normalised index kept current by a model
    observer and rebuilt weekly, and both of those fail quietly on purpose — the observer swallows
    its own errors so that an index write can never fail a merchant's product save. That is the
    right trade and it is why this page exists: until now nothing anywhere said how much of the
    catalogue was searchable, and a bulk import could leave half of it invisible with no symptom
    except shoppers not finding things.

    Three numbers are the page, and they are three different faults.

    MISSING is a product with no index row at all — what a bulk import leaves behind, because an
    importer writes rows without going through the save path the observer listens on.

    STALE is a product whose own row has moved on since its index row was written — the observer
    falling behind rather than never having run, which leaves search answering under old names.

    EMPTY NAMES is a row the indexer wrote with nothing in it. Those products are in the index and
    unfindable, so every count that does not look inside a row calls them healthy.

    The rebuild button is the only write in the operations centre. It queues work rather than doing
    it, so the page says so plainly and prints the command underneath — a shop with no queue worker
    running would otherwise press a button and watch nothing happen.
--}}

@php
    $available = $panel['available'] ?? false;
    $metrics = $panel['metrics'] ?? [];
    $locales = $panel['locales'] ?? [];
    $rebuild = $panel['rebuild'] ?? null;
    $task = $panel['task'] ?? null;

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    $statusPill = static fn (string $status) => match ($status) {
        'success' => 'mon-pill--healthy',
        'failed' => 'mon-pill--critical',
        'running' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    // A drift figure is only ever green at zero. Anything above it is work waiting, and the size of
    // the number is what decides whether it is a nuisance or an outage in the search box.
    $driftPill = static function ($metric) {
        if (!$metric || !$metric->isOk()) {
            return 'mon-pill--unknown';
        }

        return (int) $metric->value === 0 ? 'mon-pill--healthy' : 'mon-pill--warning';
    };
@endphp

@if (!$available)
    <x-k.card :title="translate('search_index')">
        <x-k.empty icon="catalog"
                   :title="translate('the_search_index_is_not_installed')"
                   :text="translate($panel['remedy'] ?? 'run_the_migrations')" />
    </x-k.card>
@else
    <x-k.card :title="translate('what_search_can_find')">
        <div class="mon-grid">
            @foreach (['catalogue_products', 'indexed_products', 'coverage', 'index_rows'] as $name)
                @isset($metrics[$name])
                    @include('admin-views.monitoring.partials._metric', ['metric' => $metrics[$name], 'label' => translate($name)])
                @endisset
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('the_index_holds_one_row_per_product_per_language_so_more_rows_than_products_is_normal') }}
        </p>
    </x-k.card>

    <x-k.card :title="translate('where_the_index_disagrees_with_the_catalogue')">
        <div class="mon-grid">
            @foreach (['missing', 'stale', 'empty_names', 'newest_write'] as $name)
                @isset($metrics[$name])
                    @include('admin-views.monitoring.partials._metric', ['metric' => $metrics[$name], 'label' => translate($name)])
                @endisset
            @endforeach
        </div>

        <p class="mon-note">
            <span class="mon-pill {{ $driftPill($metrics['missing'] ?? null) }}">{{ translate('missing') }}</span>
            {{ translate('products_the_index_has_never_seen_usually_a_bulk_import_that_wrote_rows_without_saving_a_model') }}
        </p>
        <p class="mon-note">
            <span class="mon-pill {{ $driftPill($metrics['stale'] ?? null) }}">{{ translate('stale') }}</span>
            {{ translate('products_edited_since_their_index_row_was_written_search_still_answers_under_the_old_text') }}
        </p>
        <p class="mon-note">
            <span class="mon-pill {{ $driftPill($metrics['empty_names'] ?? null) }}">{{ translate('empty_names') }}</span>
            {{ translate('rows_with_no_searchable_name_indexed_and_still_unfindable') }}
        </p>
    </x-k.card>

    @if (!empty($locales))
        <x-k.card :title="translate('rows_per_language')">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>{{ translate('language') }}</th>
                            <th class="text-end">{{ translate('rows') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($locales as $row)
                            <tr>
                                <td>{{ $row['locale'] }}</td>
                                <td class="text-end k-num">{{ $count($row['rows']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ translate('the_default_row_is_built_from_the_products_own_columns_every_other_row_comes_from_a_translation') }}
            </p>
        </x-k.card>
    @endif

    <x-k.card :title="translate('rebuilding_the_index')">
        @if ($task)
            <p class="mon-note" style="margin-block-start:0">
                <span class="mon-pill {{ $statusPill($task['status']) }}">{{ translate($task['status']) }}</span>
                {{ translate('the_weekly_rebuild_last_ran') }} {{ $task['started_at'] }}
                @if ($task['age_hours'] !== null)
                    ({{ number_format($task['age_hours'], 1) }} {{ translate('hours_ago') }})
                @endif
                @if ($task['expected_next_at'])
                    · {{ translate('next') }} {{ $task['expected_next_at'] }}
                @endif
            </p>
        @else
            <p class="mon-note" style="margin-block-start:0">
                {{ translate('the_weekly_rebuild_has_never_been_recorded_running_which_means_either_it_has_not_run_yet_or_cron_is_not_calling_the_scheduler') }}
            </p>
        @endif

        @if ($rebuild)
            <p class="mon-note">
                {{ translate('the_last_full_rebuild_finished') }} {{ $rebuild['finished_at'] ?? '—' }}
                @if (isset($rebuild['indexed']))
                    · {{ $count($rebuild['indexed']) }} {{ translate('products') }}
                @endif
                @if (isset($rebuild['duration_seconds']))
                    · {{ $count($rebuild['duration_seconds']) }} {{ translate('seconds') }}
                @endif
                @if (!empty($rebuild['requested_by']))
                    · {{ translate('requested_by') }} {{ $rebuild['requested_by'] }}
                @endif
            </p>
        @endif

        @if ($permissions->canEditSettings())
            <form action="{{ route('admin.monitoring.search.rebuild') }}" method="POST" class="mt-3">
                @csrf
                <button type="submit" class="btn btn--primary">{{ translate('rebuild_the_index') }}</button>
            </form>
            <p class="mon-note">
                {{ translate('this_queues_the_rebuild_it_does_not_run_it_here_a_catalogue_of_any_size_outlives_a_request') }}
            </p>
        @endif

        <p class="mon-note">{{ translate('or_run_it_on_the_server') }}:</p>
        <pre class="mon-pre">{{ $panel['command'] }}</pre>
    </x-k.card>
@endif
