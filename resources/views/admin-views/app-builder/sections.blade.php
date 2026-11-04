@extends('layouts.admin.app')

@section('title', translate('App_Builder_Sections'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.app-builder._nav', ['current' => 'sections'])

        {{-- What can be put on a page, as a catalogue rather than as a dropdown seen only while
             adding one. A merchant planning a page needs to know what exists before they open the
             composer, and a section the app cannot draw needs to say so here rather than after
             publishing. --}}
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <input type="search" id="ab-search" class="form-control" style="max-width:20rem"
                       placeholder="{{ translate('search_sections') }}" aria-label="{{ translate('search_sections') }}">
                <div class="d-flex flex-wrap gap-1" id="ab-filters">
                    <button type="button" class="btn btn-sm btn-outline-primary is-active" data-family="">{{ translate('all') }}</button>
                    @foreach ($catalogue as $familyKey => $family)
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-family="{{ $familyKey }}">
                            {{ translate($family['label']) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row g-3" id="ab-sections">
            @foreach ($catalogue as $familyKey => $family)
                @foreach ($family['types'] as $key => $type)
                    <div class="col-md-6 col-xl-4 ab-card"
                         data-family="{{ $familyKey }}"
                         data-search="{{ strtolower(translate($type['label']) . ' ' . $key . ' ' . translate($type['hint'] ?? '')) }}">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <h6 class="mb-1">{{ translate($type['label']) }}</h6>
                                    @if (!in_array('customer_app', $type['channels'] ?? [], true))
                                        <span class="badge badge-soft-warning">{{ translate('website_only') }}</span>
                                    @endif
                                </div>
                                <p class="text-muted small mb-2">{{ translate($type['hint'] ?? '') }}</p>

                                @if (count($type['variants'] ?? []) > 1)
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach ($type['variants'] as $variant)
                                            <span class="badge badge-soft-secondary">{{ translate($variant) }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@endsection

@push('script')
<script>
    "use strict";
    (function () {
        var search = document.getElementById('ab-search');
        var filters = document.getElementById('ab-filters');
        var cards = Array.prototype.slice.call(document.querySelectorAll('.ab-card'));
        if (!search || !filters) return;

        var family = '';

        function apply() {
            var term = search.value.trim().toLowerCase();

            cards.forEach(function (card) {
                var matchesFamily = !family || card.dataset.family === family;
                var matchesTerm = !term || card.dataset.search.indexOf(term) !== -1;
                card.hidden = !(matchesFamily && matchesTerm);
            });
        }

        search.addEventListener('input', apply);

        filters.addEventListener('click', function (event) {
            var button = event.target.closest('[data-family]');
            if (!button) return;

            family = button.dataset.family;
            filters.querySelectorAll('[data-family]').forEach(function (other) {
                other.classList.toggle('is-active', other === button);
                other.classList.toggle('btn-outline-primary', other === button);
                other.classList.toggle('btn-outline-secondary', other !== button);
            });
            apply();
        });
    })();
</script>
@endpush
