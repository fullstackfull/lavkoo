"use strict";

document.addEventListener('DOMContentLoaded', function () {
    const offcanvasEl = document.getElementById('offcanvasSetupGuide');

    if (offcanvasEl && offcanvasEl.getAttribute('data-status') === 'show') {
        const bsOffcanvas = new bootstrap.Offcanvas(offcanvasEl);
        setTimeout(() => {
            bsOffcanvas.show();
        }, 500)
    }

    loadSearchCmdKeys();
});

var audio = document.getElementById("myAudio");
function playAudio() {
    audio.play();
}

function loadSearchCmdKeys() {
    const isMac = navigator.platform.toUpperCase().includes('MAC');
    const shortcutKeys = document.querySelectorAll('.search-shortcut-key');

    shortcutKeys.forEach(el => {
        el.textContent = isMac ? '⌘+K' : 'Ctrl+K';
    });
}

function getInitialDataForPanel() {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content")
        }
    });
    $.ajax({
        url: $("#route-for-real-time-activities").data("route"),
        type: "GET",
        data: {},
        dataType: "json",
        success: function (response) {
            if (response?.new_order_count > 0) {
                playAudio();
                $("#popup-modal").appendTo("body").modal("show");
            }

            if (document.cookie.indexOf("6valley_restock_request_status=accepted") !== -1 || document.cookie.indexOf("6valley_restock_request_status=reject") !== -1) {
                $(".product-restock-stock-alert").hide();
            } else {
                if (response?.restockProductCount > 0 && response?.restockProduct) {
                    productRestockStockLimitStatus(response?.restockProduct);
                }
            }
        }
    });
}
