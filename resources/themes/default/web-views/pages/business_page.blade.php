@extends('layouts.front-end.app')

@section('title', $businessPage?->title)

@section('content')
    <div class="container for-container">
        <div class="business-pages-banner-section mt-4 {{ empty($businessPage?->description) ? 'mb-4' : '' }}"
             data-bg-img="{{ getStorageImages(path: $businessPage?->banner_full_url, type: 'business-page') }}">
            <div class="container">
                <h1 class="text-center text-capitalize font-semi-bold fs-24">{{ $businessPage?->title }}</h1>
            </div>
        </div>

        @if(!empty($businessPage?->description))
            <div class="card my-4">
                <div class="card-body">
                    <div class="for-padding">
                        {!! $businessPage?->description !!}
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
