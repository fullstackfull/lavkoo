@extends('layouts.admin.app')

@use('App\Services\Storefront\StorefrontPermissionService')

@section('title', translate('storefronts'))

@section('content')
    @php
        $canChangeTheLifecycle = StorefrontPermissionService::can(StorefrontPermissionService::LIFECYCLE);
        $canOpenTheLegalTexts = StorefrontPermissionService::can(StorefrontPermissionService::LEGAL_WRITE) || StorefrontPermissionService::can(StorefrontPermissionService::LEGAL_APPROVE);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('storefronts')" :subtitle="translate('a_storefront_is_a_draft_until_a_person_says_it_is_ready_and_sells_only_while_it_is_active')" />

        @unless ($storefrontsAreOn)
            <div class="alert alert-info fs-12" role="note">{{ translate('storefronts_are_switched_off_so_nothing_reads_these_decisions_yet') }}</div>
        @endunless

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="row g-3">
            @foreach ($storefronts as $storefront)
                <div class="col-xl-6">
                    @include('admin-views.storefronts.partials._storefront-card')
                </div>
            @endforeach
        </div>
    </div>
@endsection
