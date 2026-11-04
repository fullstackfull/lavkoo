@extends('layouts.admin.app')

@section('title', 'Integrations')

@section('content')

<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">
            Integrations
        </h1>

        <p class="text-muted">
            Configure Lynomia Chat and other external platform integrations.
        </p>
    </div>


    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif


    @if (session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif


    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    {{-- Lynomia Chat --}}
    <div class="card mb-4">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 w-100">

                <div>
                    <h4 class="mb-1">
                        Lynomia Chat
                    </h4>

                    <span class="text-muted">
                        Customer communication and WhatsApp automation integration
                    </span>
                </div>


                <div class="d-flex gap-2 flex-wrap">

                    <a
                        href="{{ route('admin.integrations.templates.index') }}"
                        class="btn btn-outline-primary"
                    >
                        Template Automations
                    </a>

                    <a
                        href="{{ route('admin.integrations.cart-marketing.index') }}"
                        class="btn btn-outline-primary"
                    >
                        Cart Marketing
                    </a>

                </div>

            </div>

        </div>


        <div class="card-body">

            <form
                method="POST"
                action="{{ route('admin.integrations.chatwoot.save') }}"
            >

                @csrf


                {{-- Enabled --}}
                <div class="form-group mb-4">

                    <div class="form-check">

                        <input
                            type="checkbox"
                            class="form-check-input"
                            name="enabled"
                            value="1"
                            id="chatwoot_enabled"
                            {{ old('enabled', $chatwoot?->enabled) ? 'checked' : '' }}
                        >

                        <label
                            class="form-check-label"
                            for="chatwoot_enabled"
                        >
                            Enable Lynomia Chat
                        </label>

                    </div>

                </div>


                {{-- Inbox ID --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        WhatsApp Inbox ID
                    </label>

                    <input
                        type="number"
                        name="inbox_id"
                        class="form-control"
                        min="1"
                        placeholder="1"
                        value="{{ old(
                            'inbox_id',
                            $chatwoot?->settings['inbox_id'] ?? ''
                        ) }}"
                    >

                    <small class="text-muted">
                        Lynomia Chat WhatsApp inbox used for outgoing messages.
                    </small>

                </div>


                {{-- Base URL --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        Base URL
                    </label>

                    <input
                        type="url"
                        name="base_url"
                        class="form-control"
                        placeholder="https://chat.example.com"
                        value="{{ old(
                            'base_url',
                            $chatwoot?->base_url
                        ) }}"
                    >

                    <small class="text-muted">
                        Your Lynomia Chat installation URL.
                    </small>

                </div>


                {{-- Account ID --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        Account ID
                    </label>

                    <input
                        type="number"
                        name="account_id"
                        class="form-control"
                        placeholder="1"
                        min="1"
                        value="{{ old(
                            'account_id',
                            $chatwoot?->settings['account_id'] ?? ''
                        ) }}"
                    >

                </div>


                {{-- API Token --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        API Access Token
                    </label>

                    <input
                        type="password"
                        name="api_access_token"
                        class="form-control"
                        autocomplete="new-password"
                        placeholder="{{ $chatwoot?->credentials
                            ? 'Leave blank to keep current token'
                            : 'Enter Lynomia Chat API access token' }}"
                    >

                    @if ($chatwoot?->credentials)

                        <small class="text-success">
                            API token is currently configured.
                        </small>

                    @else

                        <small class="text-muted">
                            No API token configured.
                        </small>

                    @endif

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Lynomia Chat Settings
                </button>

            </form>

        </div>

    </div>


    {{-- More Integrations --}}
    <div class="card">

        <div class="card-body">

            <h5 class="mb-2">
                More Integrations
            </h5>

            <p class="text-muted mb-0">
                Zender and additional communication integrations can be configured here later.
            </p>

        </div>

    </div>

</div>

@endsection