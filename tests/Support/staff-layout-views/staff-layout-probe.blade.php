{{-- Test-only stand-in for a screen that no staff scope covers: what its own content read, then the shared layout. --}}
@extends('layouts.admin.app')
@section('content')
[screen orders={{ $ordersTheScreenRead }} products={{ $productsTheScreenRead }}]
@endsection
