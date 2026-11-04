<div class="admin-pos-customer-info mb-20">
    @include('admin-views.pos.partials._admin-pos-customer-info')
</div>

<div id="cart-summary" class="mb-100 d-flex flex-column gap-20 p-0">
    @include('admin-views.pos.partials._cart')
</div>
@push('script_2')
    <script>
        'use strict';
        $('#type_ext_dis').on('change', function (){
            let type = $('#type_ext_dis').val();
            if(type === 'amount'){
                $('#dis_amount').attr('placeholder', 'Ex: 500');
            }else if(type === 'percent'){
                $('#dis_amount').attr('placeholder', 'Ex: 10%');
            }
        });
        $(function () {
            $('[data-bs-toggle="tooltip"]').tooltip()
        })
    </script>
@endpush
