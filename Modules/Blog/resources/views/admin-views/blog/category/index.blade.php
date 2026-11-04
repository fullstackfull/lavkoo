<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasCategory" aria-labelledby="offcanvasCategoryLabel" style="--bs-offcanvas-width: 600px;">
    <div class="offcanvas-header bg-section">
        <h3 class="mb-0">{{ translate('Category_Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="mb-4">
            @include("blog::admin-views.blog.category.partials._create-category")
            @include("blog::admin-views.blog.category.partials._edit-category")
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row align-items-center justify-content-between g-3 mb-20">
                    <div class="col-md-4">
                        <h4 class="m-0">{{ translate('Category_List') }}
                            @if(count($categories) > 0)
                                <span class="badge badge-info text-bg-info">{{ $categories->total() }}</span>
                            @endif
                        </h4>
                    </div>
                    <div class="col-md-8">
                        <form action="javascript:" method="POST" id="search-form" class="mb-0 px-1">
                            @csrf
                            <div class="form-group">
                                <div class="input-group">
                                    <input  id="datatableSearch" type="search" class="form-control" name="searchValue" placeholder="{{ translate('Search_by_Category_Name') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="" id="categories-table">
                    @include("blog::admin-views.blog.category.partials.table-rows")
                </div>
            </div>
        </div>
    </div>
  </div>
