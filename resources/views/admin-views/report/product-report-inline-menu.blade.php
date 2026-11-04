<div class="inline-page-menu my-4">
    <ul class="nav nav-pills nav--tab gap-3">
        <li class="nav-item"><a class="nav-link {{ Request::is('admin/report/all-product') ?'active':'' }}" href="{{route('admin.report.all-product')}}">{{translate('all_Products')}}</a></li>
        <li class="nav-item"><a class="nav-link {{ Request::is('admin/stock/product-stock') ?'active':'' }}" href="{{route('admin.stock.product-stock')}}">{{translate('product_Stock')}}</a></li>
        <li class="nav-item"><a class="nav-link {{ Request::is('admin/stock/product-in-wishlist') ?'active':'' }}" href="{{route('admin.stock.product-in-wishlist')}}">{{translate('wish_Listed_Products')}}</a></li>
    </ul>
</div>
