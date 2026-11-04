@extends('layouts.admin.app')

@section('title', translate('SEO_Health'))

@php
    $sevColour = ['critical' => 'danger', 'warning' => 'warning', 'notice' => 'info'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('SEO_Health') }}</h2>
            <p class="mb-0 fs-12">{{ translate('audited_against_the_resolved_title_and_description_for_each_product_-_translation,_then_per-product,_then_template') }}.</p>
        </div>

        @include('admin-views.seo-settings._inline-menu')

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-3">
                <div class="fs-12 text-muted">{{ translate('critical') }}</div>
                <div class="h2 mb-0 text-danger">{{ $totals['critical'] }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-3">
                <div class="fs-12 text-muted">{{ translate('warnings') }}</div>
                <div class="h2 mb-0 text-warning">{{ $totals['warning'] }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-3">
                <div class="fs-12 text-muted">{{ translate('notices') }}</div>
                <div class="h2 mb-0 text-info">{{ $totals['notice'] }}</div></div></div></div>
            <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-3">
                <div class="fs-12 text-muted">{{ translate('products_with_issues') }}</div>
                <div class="h2 mb-0">{{ count($rows) }}</div></div></div></div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive" style="overflow-x:auto">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('product') }}</th>
                                <th>{{ translate('issues') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td style="min-width:180px">
                                        <span class="fw-semibold">{{ \Illuminate\Support\Str::limit($row['product']->name, 40) }}</span>
                                        <span class="d-block fs-12 text-muted">#{{ $row['product']->id }}</span>
                                    </td>
                                    <td>
                                        <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                                            @foreach ($row['issues'] as $issue)
                                                <li>
                                                    <span class="badge badge-soft-{{ $sevColour[$issue['severity']] ?? 'secondary' }} text-capitalize">{{ translate($issue['severity']) }}</span>
                                                    <span class="ms-1">{{ $issue['message'] }}</span>
                                                    <span class="d-block fs-12 text-muted">{{ translate('fix') }}: {{ $issue['fix'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center py-4 text-muted">{{ translate('no_SEO_issues_on_this_page_of_products') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($products->hasPages())
                <div class="card-footer">{{ $products->links() }}</div>
            @endif
        </div>
    </div>
@endsection
