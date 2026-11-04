<form action="{{ route('admin.sub-category.view') }}" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSubCatFilter" aria-labelledby="offcanvasSubCatFilterLabel" aria-modal="true" role="dialog">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <label for="" class="form-label">{{ translate('Sorting') }}</label>
                <div class="bg-white rounded p-3">
                    <div class="row g-3">

                        <?php
                        $sortByList = [
                            'latest' => translate('New_to_Oldest'),
                            'oldest' => translate('Oldest_to_New'),
                            'updated' => translate('Last_Modify'),
                            'a-z' => translate('A - Z'),
                            'z-a' => translate('Z - A'),
                        ];
                        ?>

                        @foreach($sortByList as $sortByKey => $sortByItem)
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input radio--input cursor-pointer" type="radio" name="sort_by"
                                           id="sort-by-{{ $sortByKey }}" value="{{ $sortByKey }}"
                                        {{ (!request()->has('sort_by') && $sortByKey == 'latest') || request('sort_by') == $sortByKey ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="sort-by-{{ $sortByKey }}">
                                        {{ $sortByItem }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <label for="" class="form-label">{{ translate('Category') }}</label>
                <div class="bg-white rounded p-3">
                    <div class="row gx-3 gy-4 mb-3" style="--bs-gutter-y: 2rem;" id="load-more-categories-view">
                        @include("admin-views.category.offcanvas._parent-categories", ['filterParentCategories' => $filterParentCategories])
                    </div>

                    <div class="row gx-3 gy-4 mb-3" style="--bs-gutter-y: 2rem;">
                        @php
                            $visibleLimit = $filterParentCategories->perPage();
                            $totalCategories = $filterParentCategories->total();
                            $hiddenCount = $totalCategories - $visibleLimit;
                        @endphp
                        @if($hiddenCount > 0)
                            <div class="col-12 text-center load-more-categories-container">
                                <div class="my-2">
                                    <div class="spinner-border my-2 load-more-categories-spinner d-none" role="status">
                                        <span class="visually-hidden">{{ translate('Loading') }}...</span>
                                    </div>
                                </div>
                                <a href="javascript:" class="text-info-dark fw-semibold load-more-parent-categories"
                                   data-route="{{ route('admin.sub-category.load-more-categories') }}">
                                    {{ translate('See_More') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <a class="btn btn-secondary flex-grow-1" href="{{ route('admin.sub-category.view') }}">
                    {{ translate('Reset_Filter') }}
                </a>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
