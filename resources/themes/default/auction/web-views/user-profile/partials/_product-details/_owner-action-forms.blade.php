<form id="js-cancel-auction-form" method="POST" action="{{ route('auction.cancel') }}" class="d-none">
    @csrf
    <input type="hidden" name="auction_product_id" id="js-cancel-auction-product-id" value="">
</form>
<form id="js-delete-auction-form" method="POST" action="{{ route('auction.delete') }}" class="d-none">
    @csrf
    <input type="hidden" name="auction_product_id" id="js-delete-auction-product-id" value="">
</form>
<script>
    (function () {
        document.querySelectorAll('.js-cancel-auction-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const auctionId = this.dataset.auctionId;
                Swal.fire({
                    title: '{{ translate('Cancel_Auction') }}',
                    text: '{{ translate('Are_you_sure_you_want_to_cancel_this_auction?_This_action_cannot_be_undone.') }}',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '{{ translate('Yes,_Cancel_It') }}',
                    cancelButtonText: '{{ translate('No,_Keep_It') }}',
                    reverseButtons: true,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        document.getElementById('js-cancel-auction-product-id').value = auctionId;
                        document.getElementById('js-cancel-auction-form').submit();
                    }
                });
            });
        });
    })();
</script>
<script>
    (function () {
        document.querySelectorAll('.js-delete-auction-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const auctionId = this.dataset.auctionId;
                Swal.fire({
                    title: '{{ translate('Delete_Auction') }}',
                    text: '{{ translate('This_will_permanently_delete_the_auction_and_all_associated_data._This_action_cannot_be_undone.') }}',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '{{ translate('Yes,_Delete') }}',
                    cancelButtonText: '{{ translate('Cancel') }}',
                    reverseButtons: true,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        document.getElementById('js-delete-auction-product-id').value = auctionId;
                        document.getElementById('js-delete-auction-form').submit();
                    }
                });
            });
        });
    })();
</script>
