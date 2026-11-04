<div class="position-relative nav--tab-wrapper my-4">
    <ul class="nav nav-pills nav--tab">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/blog/view*') ? 'active' : '' }}" href="{{ route('admin.blog.view') }}"> {{ translate('Blog_Page') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/blog/app-download-setup*') ? 'active' : '' }}" href="{{ route('admin.blog.app-download-setup') }}">{{ translate('App_Download_Setup') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/blog/priority-setup*') ? 'active' : '' }}" href="{{ route('admin.blog.priority-setup.index') }}"> {{ translate('Priority_Setup') }}</a>
        </li>
    </ul>
    <div class="nav--tab__prev">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>
</div>
