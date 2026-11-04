@php(session(['last_order'=> false]))
<div class="offcanvas-sidebar" id="print-invoice">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="text-capitalize m-0">{{ translate('Print_Invoice') }}</h3>
            <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="d-flex justify-content-center align-items-center" id="printableArea">
                @include('vendor-views.pos.order.invoice')
            </div>
        </div>
        <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 px-sm-4 py-3">
                <a href="{{url()->previous()}}" class="btn btn-secondary flex-grow-1 non-printable">
                    {{ translate('back') }}
                </a>
                <input id="print_invoice" type="button" class="btn btn--primary flex-grow-1 non-printable action-print-pos-invoice"
                    data-print="#printableArea"
                    value="{{ translate('proceed') }}, {{ translate('if_thermal_printer_is_ready') }}"/>
            </div>
        </div>
    </div>
</div>
