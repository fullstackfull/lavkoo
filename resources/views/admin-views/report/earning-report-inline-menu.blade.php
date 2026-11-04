<div class="inline-page-menu my-4">
    <ul class="nav nav-pills nav--tab gap-3">
        <li class="nav-item"><a class="nav-link {{ Request::is('admin/report/admin-earning') ?'active':'' }}" href="{{route('admin.report.admin-earning', ['date_type' => 'this_year'])}}">{{translate('admin_Earning')}}</a></li>
        <li class="nav-item"><a class="nav-link {{ Request::is('admin/report/vendor-earning') ?'active':'' }}" href="{{route('admin.report.vendor-earning', ['date_type' => 'this_year'])}}">{{translate('vendor_Earning')}}</a></li>
    </ul>
</div>
