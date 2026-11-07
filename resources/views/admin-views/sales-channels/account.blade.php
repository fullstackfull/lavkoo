@extends('layouts.admin.app')

@section('title', $account->name . ' · ' . translate('Sales_Channels'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="$account->name" :subtitle="translate($channel->labelKey()) . ' · ' . $labels->environment($account->environment)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.products.index', ['account' => $account->id]) }}">{{ translate('Products_&_Channels') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index', ['account' => $account->id]) }}">{{ translate('Unified_Orders') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.logs.index', ['account' => $account->id]) }}">{{ translate('Channel_API_Logs') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="row g-3">
            <div class="col-xl-8">
                @include('admin-views.sales-channels.partials._account-safety')
                @include('admin-views.sales-channels.partials._ladder')
                @include('admin-views.sales-channels.partials._account-operations')
                @if ($webhook)
                    @include('admin-views.sales-channels.partials._account-webhook')
                @endif

                <form method="POST" action="{{ route('admin.sales-channels.accounts.update', $account) }}" autocomplete="off">
                    @csrf
                    <x-k.card :title="translate('account_settings')" class="mb-3">
                        @include('admin-views.sales-channels.partials._account-fields', ['creating' => false, 'secretsSet' => $secretsSet])
                    </x-k.card>
                    <button class="btn btn-primary" type="submit">{{ translate('save_settings') }}</button>
                </form>
            </div>

            <div class="col-xl-4">
                @include('admin-views.sales-channels.partials._account-connection')
                @include('admin-views.sales-channels.partials._account-matrix')
            </div>
        </div>
    </div>
@endsection
