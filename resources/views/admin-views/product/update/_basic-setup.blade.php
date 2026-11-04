<div class="card card-body">
    <div class="mb-20">
        <h2 class="mb-1">{{ translate('Basic_Setup') }}</h2>
        <p class="fs-12 mb-0">
            {{ translate('Here_you_can_setup_the_product_information_for_website.') }}
        </p>
    </div>
    <div class="row gy-4">
        <div class="col-lg-8">
            @include("admin-views.product.update._title-description")
        </div>
        <div class="col-lg-4">
            @include("admin-views.product.update._product-thumbnail")
        </div>
    </div>
</div>
