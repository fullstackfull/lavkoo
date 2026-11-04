@extends('layouts.seller.app')

@section('title', translate('access_denied'))

@php
    /* The page frame renders; only the content is replaced, so the seller keeps their bearings
       and can see what they do have access to (handoff 11 §7 level 2). */
    $module = translate(explode('.', $permissions[0] ?? 'module')[0]);
@endphp

@section('content')
    <x-sc.page-header :title="$module" />
    <div class="sc-scroll">
        <x-sc.permission :module="$module">
            {{ translate('this_page_requires_the_permission') }}
            <span class="sc-code">{{ implode(', ', $permissions) }}</span>.
            {{ translate('ask_an_owner_or_manager_to_grant_it') }}
        </x-sc.permission>
    </div>
@endsection
