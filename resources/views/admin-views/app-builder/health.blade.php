@extends('layouts.admin.app')

@section('title', translate('Experience_Health'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.app-builder._nav', ['current' => 'health'])

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0">{{ translate('experience_health') }}</h5>
                        @if ($findings === [])
                            <span class="badge badge-soft-success">{{ translate('nothing_is_quietly_wrong') }}</span>
                        @else
                            <span class="badge badge-soft-danger">{{ count($findings) }} {{ translate('findings') }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        {{-- Detection only (§58): every row names what to open, none rewrites anything. --}}
                        <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                            @forelse ($findings as $finding)
                                <li class="d-flex gap-2">
                                    @if ($finding['severity'] === 'critical')
                                        <span class="badge bg-danger align-self-start">{{ translate('critical') }}</span>
                                    @elseif ($finding['severity'] === 'warning')
                                        <span class="badge bg-warning align-self-start">{{ translate('warning') }}</span>
                                    @else
                                        <span class="badge badge-soft-info align-self-start">{{ translate('info') }}</span>
                                    @endif
                                    <div class="min-w-0">
                                        <div>{{ translate($finding['label']) }}</div>
                                        @if (!empty($finding['detail']))
                                            <small class="text-muted d-block" dir="ltr">{{ $finding['detail'] }}</small>
                                        @endif
                                    </div>
                                </li>
                            @empty
                                <li class="text-muted">{{ translate('every_check_passed') }}</li>
                            @endforelse
                        </ul>

                        <hr>
                        <h6>{{ translate('server_readiness') }}</h6>
                        <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                            @foreach ($infra as $check)
                                <li class="d-flex gap-2 align-items-start">
                                    @if ($check['ok'])
                                        <i class="fi fi-sr-check-circle text-success mt-1"></i>
                                    @else
                                        <i class="fi fi-sr-cross-circle text-danger mt-1"></i>
                                    @endif
                                    <div class="min-w-0">
                                        <span>{{ translate($check['label']) }}</span>
                                        @if (!$check['ok'] && $check['fix'])
                                            <code dir="ltr" class="d-block text-break small">{{ $check['fix'] }}</code>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('what_is_dressing_the_home_page') }}</h5></div>
                    <div class="card-body">
                        {{-- The decision trace (§60): which overlays and experiments are in play. --}}
                        <h6 class="small text-muted">{{ translate('campaign_overrides') }}
                            @if ($at) <span dir="ltr">@ {{ $at->format('Y-m-d H:i') }}</span> @endif
                        </h6>
                        @forelse ($overrides as $override)
                            <div class="d-flex gap-2 align-items-center mb-1">
                                <span class="badge bg-warning">{{ $override['slot'] }}</span>
                                <span>{{ $campaignNames[$override['campaign_id']] ?? ('#' . $override['campaign_id']) }}</span>
                                <code dir="ltr" class="small">{{ $override['section']['type'] ?? '?' }}</code>
                            </div>
                        @empty
                            <p class="text-muted small">{{ translate('no_campaign_is_dressing_the_page') }}</p>
                        @endforelse

                        {{-- Time-travel (§61): evaluate the same windows as of another moment. --}}
                        <form method="get" class="d-flex gap-2 align-items-end mt-2">
                            <div>
                                <label class="form-label small mb-1">{{ translate('preview_as_of') }}</label>
                                <input type="datetime-local" name="at" class="form-control form-control-sm"
                                       value="{{ $at?->format('Y-m-d\TH:i') }}">
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('evaluate') }}</button>
                            @if ($at)
                                <a href="{{ route('admin.app-builder.health') }}" class="btn btn-sm btn-link">{{ translate('back_to_now') }}</a>
                            @endif
                        </form>

                        <hr>
                        <h6 class="small text-muted">{{ translate('running_experiments') }}</h6>
                        @forelse ($experiments as $experiment)
                            <div class="mb-1">
                                <span class="badge bg-warning">{{ translate('LIVE') }}</span>
                                {{ $experiment->name }}
                                <small class="text-muted" dir="ltr">
                                    @foreach ($experiment->variantRows() as $variant)
                                        {{ $variant['key'] ?? '?' }}:{{ $variant['weight'] ?? 0 }}%
                                    @endforeach
                                </small>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">{{ translate('no_experiment_is_running') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('preview_as_a_kind_of_shopper') }}</h5></div>
                    <div class="card-body">
                        {{-- §62: a synthetic viewer carrying one segment — never a real customer. --}}
                        <form method="get" class="d-flex gap-2 mb-3">
                            <select name="as_segment" class="form-control form-control-sm">
                                <option value="">{{ translate('choose_a_segment') }}</option>
                                @foreach ($segments as $key => $name)
                                    <option value="{{ $key }}" @selected($asSegment === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('evaluate') }}</button>
                        </form>

                        @if ($segmentPreview !== null)
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-1">
                                @foreach ($segmentPreview as $row)
                                    <li class="d-flex gap-2 align-items-center">
                                        @if ($row['shown'])
                                            <span class="badge badge-soft-success">{{ translate('shown') }}</span>
                                        @else
                                            <span class="badge badge-soft-warning">{{ translate('hidden') }}</span>
                                        @endif
                                        <code dir="ltr" class="small">{{ $row['label'] }}</code>
                                        @if (!$row['shown'] && $row['reason'])
                                            <small class="text-muted">{{ translate('because_' . $row['reason']) }}</small>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-muted small mb-0">
                                {{ translate('pick_a_segment_to_see_exactly_which_home_sections_that_shopper_gets_and_why') }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0">
                    {{ translate('everything_on_this_page_is_an_evaluation_of_the_live_configuration_nothing_here_changes_anything') }}
                </div>
            </div>
        </div>
    </div>
@endsection
