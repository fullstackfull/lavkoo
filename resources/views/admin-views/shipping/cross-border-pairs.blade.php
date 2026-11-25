@extends('layouts.admin.app')

@section('title', translate('cross_border_selling'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cross_border_selling')" :subtitle="translate('cross_border_selling_subtitle')" />

        @if (!$ready)
            <div class="alert alert-warning fs-12" role="alert">{{ translate('the_destination_pair_table_has_not_been_created_yet') }}</div>
        @endif

        @if (!$engineOn)
            <div class="alert alert-info fs-12" role="alert">{{ translate('cross_border_shipping_is_switched_off_so_every_sale_stays_inside_its_own_country') }}</div>
        @endif

        @if ($killSwitch)
            <div class="alert alert-danger fs-12" role="alert">{{ translate('cross_border_selling_is_stopped_for_every_market') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('open_or_close_a_pair') }}</h5>
                <small class="text-muted">{{ translate('a_pair_nobody_opened_is_refused') }}</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.shipping.cross-border-pairs.record') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-sm-3">
                        <label class="form-label" for="source_country">{{ translate('sells_from') }}</label>
                        <select class="form-control" id="source_country" name="source_country" required>
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ $source }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label" for="destination_country">{{ translate('sells_into') }}</label>
                        <select class="form-control" id="destination_country" name="destination_country" required>
                            @foreach ($destinations as $destination)
                                <option value="{{ $destination }}">{{ $destination }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="open" name="open" value="1">
                            <label class="form-check-label" for="open">{{ translate('this_pair_is_open') }}</label>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn--primary">{{ translate('record') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('pairs_a_person_recorded') }}</h5>
                <small class="text-muted">{{ translate('a_recorded_pair_wins_over_the_declared_list_for_that_pair_alone') }}</small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>{{ translate('sells_from') }}</th>
                                <th>{{ translate('sells_into') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('recorded_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recorded as $pair)
                                <tr>
                                    <td>{{ $pair->source_country }}</td>
                                    <td>{{ $pair->destination_country }}</td>
                                    <td>
                                        <span class="badge {{ $pair->is_open ? 'badge-soft-success' : 'badge-soft-danger' }}">
                                            {{ $pair->is_open ? translate('open') : translate('closed') }}
                                        </span>
                                    </td>
                                    <td>{{ $pair->recorded_at }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center">{{ translate('no_pair_has_been_recorded') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('the_declared_list') }}</h5>
                <small class="text-muted">{{ translate('what_the_release_ships_with_before_anybody_changes_a_pair') }}</small>
            </div>
            <div class="card-body">
                @forelse ($declared as $source => $openTo)
                    <div class="fs-12">{{ $source }} &rarr; {{ implode(', ', (array) $openTo) }}</div>
                @empty
                    <div class="fs-12">{{ translate('the_declared_list_is_empty_so_every_market_sells_inside_its_own_country') }}</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
