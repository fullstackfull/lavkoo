@extends('layouts.admin.app')

@section('title', 'App Carousel')

@section('content')

<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">
            App Carousel
        </h1>

        <p class="text-muted">
            Manage carousel images, promotional texts and visibility in the mobile application.
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
                <div>
                    {{ $error }}
                </div>
            @endforeach

        </div>
    @endif


    <form
        method="POST"
        action="{{ route('admin.app-carousel.update') }}"
        enctype="multipart/form-data"
    >

        @csrf


        {{-- General settings --}}
        <div class="card mb-4">

            <div class="card-header">
                <h4 class="mb-0">
                    Carousel Settings
                </h4>
            </div>


            <div class="card-body">

                <div class="form-check form-switch mb-4">

                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        name="show_carousel"
                        value="1"
                        id="show_carousel"
                        {{ $settings->show_carousel ? 'checked' : '' }}
                    >

                    <label
                        class="form-check-label"
                        for="show_carousel"
                    >
                        Show carousel in app
                    </label>

                </div>


                <div class="form-check form-switch">

                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        name="show_text"
                        value="1"
                        id="show_text"
                        {{ $settings->show_text ? 'checked' : '' }}
                    >

                    <label
                        class="form-check-label"
                        for="show_text"
                    >
                        Show promotional text
                    </label>

                </div>

            </div>

        </div>


        {{-- Current images --}}
        <div class="card mb-4">

            <div class="card-header">

                <div>
                    <h4 class="mb-1">
                        Carousel Images
                    </h4>

                    <small class="text-muted">
                        Images currently displayed by the mobile application.
                    </small>
                </div>

            </div>


            <div class="card-body">

                @if (!empty($settings->images))

                    <div class="row g-3">

                        @foreach ($settings->images as $image)

                            <div class="col-md-6 col-lg-4">

                                <div class="border rounded p-2 h-100">

                                    <div
                                        class="rounded overflow-hidden mb-2"
                                        style="
                                            height: 180px;
                                            background: #f5f5f5;
                                        "
                                    >

                                        <img
                                            src="{{ $image }}"
                                            alt="Carousel image"
                                            style="
                                                width: 100%;
                                                height: 100%;
                                                object-fit: cover;
                                            "
                                        >

                                    </div>


                                    <div class="small text-muted text-break mb-2">
                                        {{ $image }}
                                    </div>


                                    <button
                                        type="submit"
                                        formaction="{{ route('admin.app-carousel.delete-image') }}"
                                        formmethod="POST"
                                        name="image"
                                        value="{{ $image }}"
                                        class="btn btn-outline-danger btn-sm w-100"
                                        onclick="return confirm('Delete this image?')"
                                    >
                                        Delete Image
                                    </button>

                                </div>

                            </div>

                        @endforeach

                    </div>

                @else

                    <div class="text-muted">
                        No carousel images configured.
                    </div>

                @endif

            </div>

        </div>


        {{-- Upload --}}
        <div class="card mb-4">

            <div class="card-header">

                <h4 class="mb-0">
                    Add Images
                </h4>

            </div>


            <div class="card-body">

                <div class="form-group">

                    <label class="form-label">
                        Upload Carousel Images
                    </label>

                    <input
                        type="file"
                        name="images[]"
                        class="form-control"
                        accept=".jpg,.jpeg,.png,.webp"
                        multiple
                    >

                    <small class="text-muted">
                        JPG, PNG or WebP. Maximum 5 MB per image.
                    </small>

                </div>

            </div>

        </div>


        {{-- Texts --}}
        <div class="card mb-4">

            <div class="card-header">

                <div>
                    <h4 class="mb-1">
                        Carousel Texts
                    </h4>

                    <small class="text-muted">
                        Promotional texts returned to the mobile application.
                    </small>
                </div>

            </div>


            <div class="card-body">

                <div id="carousel-texts">

                    @forelse ($settings->texts ?? [] as $text)

                        <div class="input-group mb-3 carousel-text-row">

                            <input
                                type="text"
                                name="texts[]"
                                class="form-control"
                                value="{{ $text }}"
                                maxlength="500"
                            >

                            <button
                                type="button"
                                class="btn btn-outline-danger remove-carousel-text"
                            >
                                Remove
                            </button>

                        </div>

                    @empty

                        <div class="input-group mb-3 carousel-text-row">

                            <input
                                type="text"
                                name="texts[]"
                                class="form-control"
                                placeholder="Enter promotional text"
                                maxlength="500"
                            >

                            <button
                                type="button"
                                class="btn btn-outline-danger remove-carousel-text"
                            >
                                Remove
                            </button>

                        </div>

                    @endforelse

                </div>


                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    id="add-carousel-text"
                >
                    + Add Text
                </button>

            </div>

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >
            Save Carousel Settings
        </button>

    </form>

</div>

@endsection


@push('script')

<script>

document.addEventListener('DOMContentLoaded', function () {

    const container =
        document.getElementById('carousel-texts');

    const addButton =
        document.getElementById('add-carousel-text');


    addButton?.addEventListener(
        'click',
        function () {

            const row = document.createElement('div');

            row.className =
                'input-group mb-3 carousel-text-row';

            row.innerHTML = `
                <input
                    type="text"
                    name="texts[]"
                    class="form-control"
                    placeholder="Enter promotional text"
                    maxlength="500"
                >

                <button
                    type="button"
                    class="btn btn-outline-danger remove-carousel-text"
                >
                    Remove
                </button>
            `;

            container.appendChild(row);
        }
    );


    container?.addEventListener(
        'click',
        function (event) {

            if (
                !event.target.classList.contains(
                    'remove-carousel-text'
                )
            ) {
                return;
            }

            const row =
                event.target.closest(
                    '.carousel-text-row'
                );

            if (row) {
                row.remove();
            }
        }
    );

});

</script>

@endpush