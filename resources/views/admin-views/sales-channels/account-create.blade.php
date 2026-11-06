@extends('layouts.admin.app')

@section('title', translate('add_channel_account'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('add_channel_account')" :subtitle="translate('add_channel_account_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @if ($channel === null)
            <div class="row g-3">
                @foreach ($channels as $choice)
                    <div class="col-md-6">
                        <x-k.card :title="translate($choice->labelKey())">
                            <p class="fs-12 text-muted">{{ $choice->value === 'amazon' ? translate('connect_amazon_help') : translate('connect_trendyol_help') }}</p>
                            <a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.accounts.create', ['channel' => $choice->value]) }}">{{ $choice->value === 'amazon' ? translate('connect_amazon') : translate('connect_trendyol') }}</a>
                        </x-k.card>
                    </div>
                @endforeach
            </div>
        @else
            @if ($errors->any())
                <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <div class="alert alert-info fs-12" role="note">{{ translate('a_new_account_is_created_disabled') }}</div>

            <form method="POST" action="{{ route('admin.sales-channels.accounts.store') }}" autocomplete="off">
                @csrf
                <input type="hidden" name="channel" value="{{ $channel->value }}">
                <x-k.card :title="translate($channel->labelKey())" class="mb-3">
                    @include('admin-views.sales-channels.partials._account-fields', ['creating' => true, 'account' => null, 'secretsSet' => [], 'carrierCodesText' => ''])
                </x-k.card>
                <button class="btn btn-primary" type="submit">{{ translate('create_account_disabled') }}</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.sales-channels.accounts.create') }}">{{ translate('choose_another_channel') }}</a>
            </form>
        @endif
    </div>
@endsection
