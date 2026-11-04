@php(session(['last_order'=> false]))
<div class="offcanvas offcanvas-end" tabindex="-1" id="print-invoice" aria-labelledby="printInvoiceLabel" style="--bs-offcanvas-width: 500px;">
    <div class="offcanvas-header bg-body">
        <h2 class="text-capitalize m-0" id="printInvoiceLabel">{{ translate('Print_Invoice') }}</h2>
        <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
            <i class="fi fi-rr-cross d-flex"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div class="flex-grow-1 p-3 d-flex justify-content-center align-items-center" id="printableArea">
            @include('admin-views.pos.order.invoice')
        </div>
        <div class="border-top pt-3 pb-3 bg-white">
        </div>
    </div>
    <div class="offcanvas-footer shadow-popup">
        <div class="d-flex justify-content-center flex-wrap flex-sm-nowrap gap-3 px-3 px-sm-4 py-3">
            <a href="{{ url()->previous() }}" class="btn btn-secondary flex-grow-1">
                {{ translate('back') }}
            </a>
            <input id="print_invoice" type="button" class="btn btn-primary flex-grow-1 action-print-pos-invoice" data-print="#printableArea" value="{{ translate('proceed') }}, {{ translate('if_thermal_printer_is_ready') }}" />
        </div>
    </div>
</div>
