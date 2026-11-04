@extends('layouts.admin.app')

@section('title', 'Manual Message')

@section('content')

<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">
            Manual Message
        </h1>

        <p class="text-muted">
            Check whether a customer can receive a normal WhatsApp message through Lynomia Chat.
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
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif


    {{-- Check number --}}
    <div class="card mb-4">

        <div class="card-header">
            <h4 class="mb-0">
                Check WhatsApp Number
            </h4>
        </div>

        <div class="card-body">

            <form
                method="POST"
                action="{{ route('admin.integrations.manual-message.check') }}"
            >
                @csrf

                <div class="row align-items-end">

                    <div class="col-md-8">

                        <label class="form-label">
                            Phone Number
                        </label>

                        <input
                            type="text"
                            name="phone"
                            class="form-control"
                            placeholder="+491234567890"
                            value="{{ old('phone') }}"
                            required
                        >

                        <small class="text-muted">
                            Include the country code.
                        </small>

                    </div>

                    <div class="col-md-4">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >
                            Check Number
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>


    @if (session('eligibility'))

        @php
            $eligibility = session('eligibility');
        @endphp

        <div class="card">

            <div class="card-header">
                <h4 class="mb-0">
                    Messaging Status
                </h4>
            </div>

            <div class="card-body">

                @if ($eligibility['eligible'])

                    <div class="alert alert-success">

                        <strong>
                            Messaging is available
                        </strong>

                        <div class="mt-1">
                            {{ $eligibility['reason'] }}
                        </div>

                    </div>

                    <div class="mb-4">

                        <div>
                            <strong>Phone:</strong>
                            {{ $eligibility['phone'] }}
                        </div>

                        @if (!empty($eligibility['contact']['name']))
                            <div>
                                <strong>Customer:</strong>
                                {{ $eligibility['contact']['name'] }}
                            </div>
                        @endif

                        <div>
                            <strong>Conversation:</strong>
                            #{{ $eligibility['conversation_id'] }}
                        </div>

                        @if (!empty($eligibility['last_inbound_at']))
                            <div>
                                <strong>Last inbound:</strong>
                                {{ $eligibility['last_inbound_at'] }}
                            </div>
                        @endif

                    </div>


                    <form
                        method="POST"
                        action="{{ route('admin.integrations.manual-message.send') }}"
                    >
                        @csrf

                        <input
                            type="hidden"
                            name="phone"
                            value="{{ $eligibility['phone'] }}"
                        >

                        <input
                            type="hidden"
                            name="conversation_id"
                            value="{{ $eligibility['conversation_id'] }}"
                        >


                        <div class="form-group mb-3">

                            <label class="form-label">
                                Message
                            </label>

                            <textarea
                                name="message"
                                class="form-control"
                                rows="6"
                                maxlength="4000"
                                placeholder="Write your message..."
                                required
                            ></textarea>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Send Message
                        </button>

                    </form>

                @else

                    <div class="alert alert-warning mb-0">

                        <strong>
                            Normal messaging is not available
                        </strong>

                        <div class="mt-1">
                            {{ $eligibility['reason'] }}
                        </div>

                    </div>

                @endif

            </div>

        </div>

    @endif

</div>

@endsection