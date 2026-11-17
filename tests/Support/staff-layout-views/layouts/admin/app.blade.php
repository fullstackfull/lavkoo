{{-- Test-only stand-in for the admin layout: it prints what the shared layout reads of the orders, read the way the real sidebar and header read it (layouts/admin/partials/v2/_header.blade.php). --}}
[layout-orders {{ $sidebarOrderCounts->sortKeys()->map(fn ($total, $status) => $status . '=' . $total)->implode(' ') }}][layout-total {{ $sidebarTotalOrders }}]
[layout-refunds {{ $sidebarRefundCounts->sortKeys()->map(fn ($total, $status) => $status . '=' . $total)->implode(' ') }}]
[layout-latest {{ \App\Models\Order::where('order_status', 'pending')->with('details.productAllStatus')->orderBy('id', 'desc')->take(5)->get()->pluck('id')->implode(',') }}]
@yield('content')
