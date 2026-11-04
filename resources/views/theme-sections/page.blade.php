@extends('layouts.front-end.app')

{{-- A page the merchant composed, rendered by the shell every composed page uses.

     Rendered before any section opens, for the same reason the home page does it: a view that
     throws inside a section takes Laravel's view state with it, and the guard is only worth having
     where a flush costs nothing. --}}
@php
    $__composed = '';
    try {
        $__composed = trim(view('theme-sections.home', ['__pageSlug' => $pageSlug])->render());
    } catch (\Throwable $composedPageError) {
        report($composedPageError);
        $__composed = '';
    }
@endphp

@section('title', $pageTitle)

@section('content')
    @if ($__composed !== '')
        {!! $__composed !!}
    @else
        {{-- The page exists and has nothing on it yet. Saying so beats a blank screen that reads
             as a broken shop. --}}
        <div class="container py-5 text-center">
            <h1 class="h4">{{ $pageTitle }}</h1>
            <p class="text-muted mb-0">{{ translate('this_page_has_no_content_yet') }}</p>
        </div>
    @endif
@endsection
