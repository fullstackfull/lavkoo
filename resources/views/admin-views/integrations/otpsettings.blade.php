@extends('layouts.admin.app')

@section('title', 'Zender Settings')

@section('content')

@php
    $defaultOtpMessage = 'Your verification code is {{otp}}';
@endphp

<div class="content container-fluid">

    {{-- Page Header --}}
    <div class="mb-4">
        <h1 class="page-header-title">
            Zender Settings
        </h1>

        <p class="text-muted">
            Configure Zender for OTP delivery via SMS or WhatsApp.
        </p>
    </div>


    {{-- Success Message --}}
    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif


    {{-- Error Message --}}
    @if (session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif


    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    <div class="card">

        {{-- Card Header --}}
        <div class="card-header">
            <div>
                <h4 class="mb-1">
                    Zender OTP Integration
                </h4>

                <span class="text-muted">
                    Configure your Zender connection and OTP sending method.
                </span>
            </div>
        </div>


        <div class="card-body">

            <form
                method="POST"
                action="{{ route('admin.zender.settings.update') }}"
            >

                @csrf
                @method('PUT')


                {{-- Enable / Disable --}}
                <div class="form-group mb-4">

                    <div class="form-check">

                        <input
                            type="checkbox"
                            class="form-check-input"
                            name="enabled"
                            value="1"
                            id="zender_enabled"
                            {{ old('enabled', $settings->enabled ?? false) ? 'checked' : '' }}
                        >

                        <label
                            class="form-check-label"
                            for="zender_enabled"
                        >
                            Enable Zender OTP
                        </label>

                    </div>

                    <small class="text-muted">
                        Enable or disable OTP delivery through Zender.
                    </small>

                </div>


                <hr>


                {{-- Base URL --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        Base URL
                    </label>

                    <input
                        type="url"
                        name="base_url"
                        class="form-control"
                        placeholder="https://otp.lynomia.com"
                        value="{{ old('base_url', $settings->base_url ?? 'https://otp.lynomia.com') }}"
                        required
                    >

                    <small class="text-muted">
                        Zender installation URL. OTP requests will be sent to
                        <strong>/api/send/otp</strong>.
                    </small>

                </div>


                {{-- API Secret --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        API Secret
                    </label>

                    <input
                        type="password"
                        name="secret"
                        class="form-control"
                        autocomplete="new-password"
                        placeholder="{{ !empty($settings->secret) ? 'Leave blank to keep current secret' : 'Enter Zender API secret' }}"
                    >

                    @if (!empty($settings->secret))

                        <small class="text-success">
                            API Secret is configured. Leave this field blank to keep the current secret.
                        </small>

                    @else

                        <small class="text-muted">
                            Enter the API Secret generated from your Zender API Keys page.
                        </small>

                    @endif

                </div>


                <hr>


                {{-- Sending Type --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        OTP Sending Method
                    </label>

                    <select
                        name="type"
                        id="zender_type"
                        class="form-control"
                        required
                    >

                        <option
                            value="sms"
                            {{ old('type', $settings->type ?? 'sms') === 'sms' ? 'selected' : '' }}
                        >
                            SMS
                        </option>

                        <option
                            value="whatsapp"
                            {{ old('type', $settings->type ?? 'sms') === 'whatsapp' ? 'selected' : '' }}
                        >
                            WhatsApp
                        </option>

                    </select>

                    <small class="text-muted">
                        Choose how OTP messages should be delivered.
                    </small>

                </div>


                {{-- OTP Message --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        OTP Message
                    </label>

                    <textarea
                        name="message"
                        class="form-control"
                        rows="3"
                        required
                    >{{ old('message', $settings->message ?? $defaultOtpMessage) }}</textarea>

                    <small class="text-muted">
                        Use <strong>@{{otp}}</strong> where you want the OTP code to appear.
                    </small>

                </div>


                {{-- Expiration --}}
                <div class="form-group mb-4">

                    <label class="form-label">
                        OTP Expiration
                    </label>

                    <div class="input-group">

                        <input
                            type="number"
                            name="expire"
                            class="form-control"
                            min="30"
                            value="{{ old('expire', $settings->expire ?? 300) }}"
                            required
                        >

                        <span class="input-group-text">
                            seconds
                        </span>

                    </div>

                    <small class="text-muted">
                        Default is 300 seconds (5 minutes).
                    </small>

                </div>


                {{--
                |--------------------------------------------------------------------------
                | SMS Settings
                |--------------------------------------------------------------------------
                --}}

                <div id="sms-settings">

                    <hr>

                    <h5 class="mb-3">
                        SMS Settings
                    </h5>


                    {{-- SMS Mode --}}
                    <div class="form-group mb-4">

                        <label class="form-label">
                            SMS Sending Mode
                        </label>

                        <select
                            name="mode"
                            id="sms_mode"
                            class="form-control"
                        >

                            <option value="">
                                Select sending mode
                            </option>

                            <option
                                value="devices"
                                {{ old('mode', $settings->mode ?? '') === 'devices' ? 'selected' : '' }}
                            >
                                Linked Android Device
                            </option>

                            <option
                                value="credits"
                                {{ old('mode', $settings->mode ?? '') === 'credits' ? 'selected' : '' }}
                            >
                                Credits / Gateway
                            </option>

                        </select>

                        <small class="text-muted">
                            Choose whether SMS messages are sent through a linked device or Zender credits.
                        </small>

                    </div>


                    {{-- Device Settings --}}
                    <div id="device-settings">

                        <div class="form-group mb-4">

                            <label class="form-label">
                                Device ID
                            </label>

                            <input
                                type="text"
                                name="device"
                                class="form-control"
                                placeholder="Enter linked device ID"
                                value="{{ old('device', $settings->device ?? '') }}"
                            >

                            <small class="text-muted">
                                Unique ID of the linked Android device.
                            </small>

                        </div>


                        {{-- SIM --}}
                        <div class="form-group mb-4">

                            <label class="form-label">
                                SIM Slot
                            </label>

                            <select
                                name="sim"
                                class="form-control"
                            >

                                <option value="">
                                    Select SIM
                                </option>

                                <option
                                    value="1"
                                    {{ (string) old('sim', $settings->sim ?? '') === '1' ? 'selected' : '' }}
                                >
                                    SIM 1
                                </option>

                                <option
                                    value="2"
                                    {{ (string) old('sim', $settings->sim ?? '') === '2' ? 'selected' : '' }}
                                >
                                    SIM 2
                                </option>

                            </select>

                        </div>

                    </div>


                    {{-- Gateway Settings --}}
                    <div id="gateway-settings">

                        <div class="form-group mb-4">

                            <label class="form-label">
                                Gateway ID
                            </label>

                            <input
                                type="text"
                                name="gateway"
                                class="form-control"
                                placeholder="Enter Gateway ID"
                                value="{{ old('gateway', $settings->gateway ?? '') }}"
                            >

                            <small class="text-muted">
                                Partner device unique ID or gateway ID used for credits mode.
                            </small>

                        </div>

                    </div>

                </div>


                {{--
                |--------------------------------------------------------------------------
                | WhatsApp Settings
                |--------------------------------------------------------------------------
                --}}

                <div id="whatsapp-settings">

                    <hr>

                    <h5 class="mb-3">
                        WhatsApp Settings
                    </h5>


                    {{-- WhatsApp Account --}}
                    <div class="form-group mb-4">

                        <label class="form-label">
                            WhatsApp Account ID
                        </label>

                        <input
                            type="text"
                            name="account"
                            class="form-control"
                            placeholder="Enter WhatsApp Account ID"
                            value="{{ old('account', $settings->account ?? '') }}"
                        >

                        <small class="text-muted">
                            Unique ID of the WhatsApp account configured in Zender.
                        </small>

                    </div>


                    {{-- Priority --}}
                    <div class="form-group mb-4">

                        <label class="form-label">
                            Priority
                        </label>

                        <select
                            name="priority"
                            class="form-control"
                        >

                            <option
                                value="2"
                                {{ (string) old('priority', $settings->priority ?? 2) === '2' ? 'selected' : '' }}
                            >
                                Normal
                            </option>

                            <option
                                value="1"
                                {{ (string) old('priority', $settings->priority ?? 2) === '1' ? 'selected' : '' }}
                            >
                                Priority / Immediate
                            </option>

                        </select>

                        <small class="text-muted">
                            Priority mode sends the WhatsApp message immediately.
                        </small>

                    </div>

                </div>


                <hr>


                {{-- Save Button --}}
                <div class="d-flex justify-content-end">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Save Zender Settings
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>
<hr class="my-4">

<div class="row">

    <div class="col-md-8">

        <h5 class="mb-2">
            Test OTP Delivery
        </h5>

        <p class="text-muted">
            Enter a phone number in E.164 format and send a test OTP using the currently saved Zender settings.
        </p>

        <div id="zender-test-alert"></div>

        <form id="zender-test-form">

            @csrf

            <div class="form-group mb-3">

                <label class="form-label">
                    Phone Number
                </label>

                <input
                    type="text"
                    id="zender_test_phone"
                    class="form-control"
                    placeholder="+963XXXXXXXXX"
                    required
                >

                <small class="text-muted">
                    Use E.164 format, for example +963XXXXXXXXX.
                </small>

            </div>

            <button
                type="submit"
                class="btn btn-outline-primary"
                id="zender_test_button"
            >
                Send Test OTP
            </button>

        </form>

    </div>

</div>

{{-- Dynamic Settings --}}
<script>


const testForm = document.getElementById('zender-test-form');
const testPhone = document.getElementById('zender_test_phone');
const testButton = document.getElementById('zender_test_button');
const testAlert = document.getElementById('zender-test-alert');

if (testForm) {

    testForm.addEventListener('submit', async function (event) {

        event.preventDefault();

        const phone = testPhone.value.trim();

        if (!phone) {
            return;
        }

        testButton.disabled = true;
        testButton.innerText = 'Sending...';

        testAlert.innerHTML = '';

        try {

            const response = await fetch(
                "{{ route('admin.zender.test') }}",
                {
                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },

                    body: JSON.stringify({
                        phone: phone
                    })
                }
            );

            const data = await response.json();

            if (response.ok && data.success) {

                testAlert.innerHTML = `
                    <div class="alert alert-success">
                        ${data.message ?? 'Test OTP sent successfully.'}
                    </div>
                `;

            } else {

                let message =
                    data.message ??
                    'Failed to send test OTP.';

                if (data.data && typeof data.data === 'object') {
                    message += '<br><small>' +
                        JSON.stringify(data.data) +
                        '</small>';
                }

                testAlert.innerHTML = `
                    <div class="alert alert-danger">
                        ${message}
                    </div>
                `;

            }

        } catch (error) {

            testAlert.innerHTML = `
                <div class="alert alert-danger">
                    Could not connect to the server.
                </div>
            `;

        } finally {

            testButton.disabled = false;
            testButton.innerText = 'Send Test OTP';

        }

    });

}
document.addEventListener('DOMContentLoaded', function () {

    const type = document.getElementById('zender_type');
    const smsSettings = document.getElementById('sms-settings');
    const whatsappSettings = document.getElementById('whatsapp-settings');

    const smsMode = document.getElementById('sms_mode');
    const deviceSettings = document.getElementById('device-settings');
    const gatewaySettings = document.getElementById('gateway-settings');


    function updateSendingType() {

        if (type.value === 'sms') {

            smsSettings.style.display = 'block';
            whatsappSettings.style.display = 'none';

        } else {

            smsSettings.style.display = 'none';
            whatsappSettings.style.display = 'block';

        }

    }


    function updateSmsMode() {

        if (smsMode.value === 'devices') {

            deviceSettings.style.display = 'block';
            gatewaySettings.style.display = 'none';

        } else if (smsMode.value === 'credits') {

            deviceSettings.style.display = 'none';
            gatewaySettings.style.display = 'block';

        } else {

            deviceSettings.style.display = 'none';
            gatewaySettings.style.display = 'none';

        }

    }


    type.addEventListener('change', updateSendingType);
    smsMode.addEventListener('change', updateSmsMode);

    updateSendingType();
    updateSmsMode();

});
</script>

@endsection