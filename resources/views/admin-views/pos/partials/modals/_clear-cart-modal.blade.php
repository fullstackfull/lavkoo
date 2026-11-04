<div class="modal fade" id="clearCartModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body text-center px-4 px-sm-5 pb-5">
                <div class="mb-30">
                    <img width="64" height="64" src="{{ dynamicAsset(path: 'public/assets/back-end/img/modal/clear-cart.png') }}" alt="">
                </div>

                <h3>{{ translate('Are_you_sure_you_want_to_clear_your_cart') }} ?</h3>
                <p class="mb-30">{{ translate('This_action_will_permanently_remove_all_items_from_your_current_cart') }}. </p>

                <form action="{{ route('admin.pos.clear-cart-ids') }}" method="GET">
                    @csrf
                    <div class="d-flex justify-content-center gap-3 pb-0">
                        <button type="button" class="btn btn-secondary min-w-120" data-bs-dismiss="modal">
                            {{ translate('no') }}
                        </button>
                        <button type="submit" class="btn btn-danger min-w-120">
                            {{ translate('yes_clear') }}
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
