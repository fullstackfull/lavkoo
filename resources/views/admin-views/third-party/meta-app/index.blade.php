```blade
@extends('layouts.admin.app')

@section('title', translate('Meta_App_Tracking'))

@section('content')
<div class="content container-fluid">

    <div class="mb-3">
        <h2 class="h1 mb-0">
            {{ translate('Meta_App_Tracking') }}
        </h2>
    </div>

    <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded mb-3">
        {{ translate('Configure_Meta_SDK_settings_and_choose_which_app_events_should_be_sent_to_Meta.') }}
    </div>

    <div class="card">
        <div class="card-body">

            @php
                $events = [
                    'app_open' => 'App Open',
                    'complete_registration' => 'Complete Registration',
                    'search' => 'Search',
                    'view_content' => 'View Content',
                    'add_to_cart' => 'Add To Cart',
                    'initiate_checkout' => 'Initiate Checkout',
                    'purchase' => 'Purchase',
                ];

                $savedEvents = $integration?->events ?? [];
            @endphp

            <form action="{{ route('admin.meta-app.store') }}" method="POST">
                @csrf

                <div class="row g-3">

                    {{-- App Type --}}
                    <div class="col-md-6">
                        <label class="form-label">
                            {{ translate('App_Type') }}
                        </label>

                        <select
                            name="app_type"
                            id="app_type"
                            class="form-control"
                            required
                            onchange="reloadMetaSettings()"
                        >
                            <option
                                value="customer"
                                {{ $appType === 'customer' ? 'selected' : '' }}
                            >
                                {{ translate('Customer_App') }}
                            </option>

                          <!--   <option
                                value="vendor"
                                {{ $appType === 'vendor' ? 'selected' : '' }}
                            >
                                {{ translate('Vendor_App') }}
                            </option>

                            <option
                                value="delivery"
                                {{ $appType === 'delivery' ? 'selected' : '' }}
                            >
                                {{ translate('Delivery_App') }}
                            </option> -->
                      
                      
                      
                        </select>
                    </div>

                    {{-- Platform --}}
                    <div class="col-md-6">
                        <label class="form-label">
                            {{ translate('Platform') }}
                        </label>

                        <select
                            name="platform"
                            id="platform"
                            class="form-control"
                            required
                            onchange="reloadMetaSettings()"
                        >
                            <option
                                value="android"
                                {{ $platform === 'android' ? 'selected' : '' }}
                            >
                                Android
                            </option>

                            <option
                                value="ios"
                                {{ $platform === 'ios' ? 'selected' : '' }}
                            >
                                iOS
                            </option>
                        </select>
                    </div>

                    {{-- Meta App ID --}}
                    <div class="col-md-12">
                        <label class="form-label">
                            {{ translate('Meta_App_ID') }}
                        </label>

                        <input
                            type="text"
                            name="meta_app_id"
                            class="form-control"
                            value="{{ old('meta_app_id', $integration?->meta_app_id) }}"
                            placeholder="123456789012345"
                        >
                    </div>



                    {{-- Meta Client Token --}}
<div class="col-md-12">
    <label class="form-label">
        {{ translate('Meta_Client_Token') }}
    </label>

    <input
        type="text"
        name="client_token"
        class="form-control"
        value="{{ old('client_token', $integration?->client_token) }}"
        placeholder="{{ translate('Enter_Meta_Client_Token') }}"
    >
</div>


                    {{-- Android Package Name --}}
                    @if($platform === 'android')
                        <div class="col-md-12">
                            <label class="form-label">
                                {{ translate('Android_Package_Name') }}
                            </label>

                            <input
                                type="text"
                                name="package_name"
                                class="form-control"
                                value="{{ old('package_name', $integration?->package_name) }}"
                                placeholder="com.example.app"
                            >
                        </div>
                    @endif

                    {{-- iOS Bundle ID --}}
                    @if($platform === 'ios')
                        <div class="col-md-12">
                            <label class="form-label">
                                {{ translate('iOS_Bundle_ID') }}
                            </label>

                            <input
                                type="text"
                                name="bundle_id"
                                class="form-control"
                                value="{{ old('bundle_id', $integration?->bundle_id) }}"
                                placeholder="com.example.app"
                            >
                        </div>
                    @endif

                </div>

                <hr class="my-4">

                {{-- Events --}}
                <h4 class="mb-3">
                    {{ translate('App_Events') }}
                </h4>

                <div class="row g-3">
                    @foreach($events as $key => $label)
                        <div class="col-md-4">
                            <label class="d-flex align-items-center gap-2">
                                <input
                                    type="checkbox"
                                    name="events[{{ $key }}]"
                                    value="1"
                                    {{ !empty($savedEvents[$key]) ? 'checked' : '' }}
                                >

                                <span>
                                    {{ $label }}
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <hr class="my-4">

                {{-- Active --}}
                <div class="d-flex align-items-center gap-3">
                    <label class="switcher">
                        <input
                            class="switcher_input"
                            type="checkbox"
                            name="is_active"
                            value="1"
                            {{ $integration?->is_active ? 'checked' : '' }}
                        >

                        <span class="switcher_control"></span>
                    </label>

                    <span>
                        {{ translate('Enable_Meta_App_Tracking') }}
                    </span>
                </div>

                {{-- Save --}}
                <div class="mt-4">
                    <button
                        type="submit"
                        class="btn btn-primary px-4"
                    >
                        {{ translate('Save') }}
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<script>
    function reloadMetaSettings() {
        const appType = document.getElementById('app_type').value;
        const platform = document.getElementById('platform').value;

        const url = new URL(
            "{{ route('admin.meta-app.index') }}",
            window.location.origin
        );

        url.searchParams.set('app_type', appType);
        url.searchParams.set('platform', platform);

        window.location.href = url.toString();
    }
</script>
@endsection
```