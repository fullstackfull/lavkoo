"use strict";

$(".delete-data").on("click", function () {
    let getText = $("#get-confirm-and-cancel-button-text-for-delete-country-name");
    Swal.fire({
        title: getText.data("sure"),
        text: getText.data("text"),
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#1455AC",
        cancelButtonColor: "#d33",
        cancelButtonText: getText.data("cancel"),
        confirmButtonText: getText.data("confirm"),
        reverseButtons: true,
    }).then((result) => {
        if (result.value) {
            $("#" + $(this).data("id")).submit();
        }
    });
});

function validateDateRangePickerDateInput(e) {
    if (
        $.inArray(e.keyCode, [46, 8, 9, 27, 13]) !== -1 ||
        (e.keyCode === 65 && e.ctrlKey === true) ||
        (e.keyCode >= 35 && e.keyCode <= 39)
    ) {
        return;
    }
    if (
        (e.shiftKey || e.keyCode < 48 || e.keyCode > 57) &&
        (e.keyCode < 96 || e.keyCode > 105) &&
        e.keyCode !== 191
    ) {
        e.preventDefault();
    }
}

function changeInputTypeForDateRangePicker(element) {
    try {
        element.on("keydown", function (event) {
            validateDateRangePickerDateInput(event);
        });

        if (element.val()) {
            var dateRangePicker = $(".js-daterangepicker-with-range");
            dateRangePicker
                .removeAttr("readonly")
                .removeClass("cursor-pointer");
        }
    } catch (e) {
    }
}

function submitStatusUpdateForm(formId) {
    const form = $("#" + formId);
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    let updateText = $("#get-update-status-message");
    $.ajax({
        url: form.attr("action"),
        method: form.attr("method"),
        data: form.serialize(),
        success: function (data) {
            switch (form.data("from")) {
                case "deal":
                    toastMagic.success(updateText.data("text"));
                    location.reload();
                    break;
                case "storage-connection-type":
                    if (data.status) {
                        toastMagic.success(updateText.data("text"));
                    } else {
                        toastMagic.error(data.message);
                    }
                    location.reload();
                    break;
                case "currency":
                    if (data.status) {
                        toastMagic.success(updateText.data("text"));
                    } else {
                        toastMagic.error(data.message);
                    }
                    location.reload();
                    break;
                case "default-withdraw-method-status":
                    let defaultWithdrawMethodMessage = $(
                        "#get-withdrawal-method-default-text"
                    );
                    if (data.success) {
                        toastMagic.success(
                            defaultWithdrawMethodMessage.data("success")
                        );
                    } else {
                        toastMagic.error(
                            defaultWithdrawMethodMessage.data("error")
                        );
                    }
                    location.reload();
                    break;

                case "withdraw-method-status":
                    if (data.success) {
                        toastMagic.success(updateText.data("text"));
                    } else {
                        toastMagic.error(updateText.data("error"));
                    }
                    location.reload();
                    break;

                case "featured-product-status":
                    toastMagic.success(
                        $("#get-featured-status-message").data("success")
                    );
                    break;
                case "product-status-update":
                    if (data.success) {
                        toastMagic.success(updateText.data("text"));
                    } else {
                        toastMagic.error(updateText.data("error"));
                        location.reload();
                    }
                    break;

                case "shop":
                case "delivery-restriction":
                case "default-language":
                    toastMagic.success(data.message);
                    location.reload();
                    break;
                case "product-status":
                    if (data.success) {
                        toastMagic.success(data.message);
                    } else {
                        toastMagic.error(data.message);
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    }
                    break;
                case "maintenance-mode":
                    if (data.success) {
                        toastMagic.success(data.message);
                    } else {
                        toastMagic.info(data.message);
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    }
                    break;
                case "all-page-banner":
                    if (data.status) {
                        toastMagic.success(data.message);
                    } else {
                        toastMagic.info(data.message);
                    }
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                    break;
                case "clearance-sale":
                    if (data.status) {
                        toastMagic.success(data.message);
                    } else if (!data.status) {
                        toastMagic.error(data.message);
                    } else {
                        toastMagic.info(data.message);
                    }
                    setTimeout(function () {
                        location.reload();
                    }, 2500);
                    break;
                default:
                    toastMagic.success(updateText.data("text"));
                    break;
            }
        },
    });
}

function deleteDataWithoutForm() {
    $(".delete-data-without-form").on("click", function () {
        let getText = $("#get-confirm-and-cancel-button-text-for-delete");
        let dataFrom = $(this).data("from");
        Swal.fire({
            title: getText.data("sure"),
            text: getText.data("text"),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#1455AC",
            cancelButtonColor: "#d33",
            cancelButtonText: getText.data("cancel"),
            confirmButtonText: getText.data("confirm"),
            reverseButtons: true,
        }).then((result) => {
            if (result.value) {
                $.ajaxSetup({
                    headers: {
                        "X-CSRF-TOKEN": $('meta[name="_token"]').attr(
                            "content"
                        ),
                    },
                });
                let id = $(this).data("id");
                $.ajax({
                    url: $(this).data("action"),
                    method: "POST",
                    data: {id: id},
                    success: function (response) {
                        if (dataFrom == "currency") {
                            if (response.status == 1) {
                                toastMagic.success(
                                    $("#get-delete-currency-message").data(
                                        "success"
                                    )
                                );
                            } else {
                                toastMagic.warning($("#get-delete-currency-message").data("warning"));
                            }
                        } else {
                            // toastMagic.success(
                            //     $("#get-deleted-message").data("text")
                            // );
                        }

                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    },
                });
            }
        });
    });
}

deleteDataWithoutForm();

$(".form-submit").on("click", function () {
    let getText = $("#get-confirm-and-cancel-button-text");
    let targetUrl = $(this).data("redirect-route");
    const getFormId = $(this).data("form-id");
    Swal.fire({
        title: getText.data("sure"),
        text: $(this).data("message"),
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#1455AC",
        cancelButtonColor: "#d33",
        cancelButtonText: getText.data("cancel"),
        confirmButtonText: getText.data("confirm"),
        reverseButtons: true,
    }).then((result) => {
        if (result.value) {
            let formData = new FormData(document.getElementById(getFormId));
            $.ajaxSetup({
                headers: {
                    "X-XSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                        "content"
                    ),
                },
            });
            $.post({
                url: $("#" + getFormId).attr("action"),
                data: formData,
                contentType: false,
                processData: false,
                beforeSend: function () {
                    $("#loading").fadeIn();
                },
                success: function (data) {
                    if (data.errors) {
                        for (let index = 0; index < data.errors.length; index++) {
                            setTimeout(() => {
                                toastMagic.error(data.errors[index].message);
                            }, index * 500);
                        }
                    } else if (data.error) {
                        toastMagic.error(data.error);
                    } else {
                        toastMagic.success(data.message);
                        setTimeout(() => {
                            if (targetUrl) {
                                location.href = targetUrl;
                            } else {
                                location.reload();
                            }
                        }, 2000)
                    }
                },
                complete: function () {
                    $("#loading").fadeOut();
                },
            });
        }
    });
});

$('.reset-form').on('click', function () {
    window.location.reload();
});

$("#robotsMetaContentPageSelect").on("change", function () {
    robotsMetaContentPageSelect();
});

function robotsMetaContentPageSelect() {
    let value = $("#robotsMetaContentPageSelect").val();
    let routePath = $("#robotsMetaContentPageURoutes").data(
        value.toLowerCase()
    );
    $("#robotsMetaContentPageUrl").val(routePath);
}

$('.no-reload-form').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);

    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });

    $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: $form.serialize(),
        success: function (response) {
            toastMagic.success(response.message);

            if ($form.hasClass('reload-true')) {
                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        },
    });
});


$('.status-update-form').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: $form.serialize(),
        success: function (response) {
            if (response.status) {
                toastMagic.success(response.message);
            } else {
                toastMagic.warning(response.message);
            }
            if ($form.hasClass('reload-true')) {
                setTimeout(() => location.reload(), 2000);
            }
        },
        error: function (xhr) {
            let message = "Something went wrong.";
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
                toastMagic.error(message);
                return;
            }
            if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                const errors = xhr.responseJSON.errors;
                Object.keys(errors).forEach(function (key) {
                    errors[key].forEach(function (errMsg) {
                        toastMagic.error(errMsg);
                    });
                });
                return;
            }
            if (xhr.status === 500) {
                toastMagic.error("Server error. Please try again later.");
                return;
            }
            if (xhr.status === 0) {
                toastMagic.error("Network error. Check your internet connection.");
                return;
            }
            toastMagic.error(message);
        }

    });
});



$(document).ready(function () {

    // reset dropdown value
    $('button[type="reset"]').on('click', function () {
        const form = $(this).closest('form');
        setTimeout(function () {
            const themeRatio = form.find('#theme_ratio');
            if (themeRatio.length) {
                themeRatio.html('');
            }
        }, 10);
    });



    $('.js-daterangepicker').each(function () {
        const $input = $(this);
        const isPreviousDateAllowed = $input.hasClass('previous-date-true');
        const isPlaceholderMode = $input.hasClass('placeholder-mode-true'); // new check

        $input.daterangepicker({
            autoUpdateInput: !isPlaceholderMode, // If placeholder mode, don't auto update
            locale: {
                format: 'DD MMM YYYY'
            },
            minDate: !isPreviousDateAllowed ? moment() : false
        });

        if (isPlaceholderMode) {
            $input.attr('placeholder', 'DD MMM YYYY - DD MMM YYYY');
            $input.on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format('DD MMM YYYY') + ' - ' + picker.endDate.format('DD MMM YYYY'));
            });
            $input.on('cancel.daterangepicker', function (ev, picker) {
                $(this).val('');
            });
        }
    });

    let checkIsOffcanvasSetupDataValue = $('#check-offcanvas-setup-guide').data('value')?.toString();
    let checkIsOffcanvasSetupGuideEnable = checkIsOffcanvasSetupDataValue === 'true' || checkIsOffcanvasSetupDataValue === '1';

    if (checkIsOffcanvasSetupGuideEnable) {
        setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.delete('offcanvasShow');
            window.history.replaceState({}, document.title, url.toString());
        }, 3000);
    }

    // Hide daterangepicker on page scroll. We MUST close via the picker's
    // own .hide() — calling jQuery `.hide()` on the popup directly only
    // sets display: none and never updates the library's internal
    // `isShowing` flag. After that, the next click on the input toggles
    // the flag from "showing" → "hidden" (a no-op since CSS already hid
    // it), and the user has to click a SECOND time to actually re-open
    // the calendar. Iterating over the known picker-input classes and
    // calling `instance.hide()` keeps the flag in sync.
    function hideOpenDateRangePickers() {
        var pickerInputs = $(
            '.js-daterangepicker, .js-daterangepicker-with-range, ' +
            '.js-daterangepicker-times-sec, ' +
            '.js-date-range-picker-only-times, .date-range-js'
        );
        pickerInputs.each(function () {
            var picker = $(this).data('daterangepicker');
            if (picker && picker.isShowing) {
                picker.hide();
            }
        });
    }

    var daterangepickerScrolling = false;
    document.addEventListener('scroll', function (e) {
        // Don't hide if the scroll is happening inside the daterangepicker itself
        if ($(e.target).closest('.daterangepicker').length) return;
        hideOpenDateRangePickers();
    }, true);

    // Also hide on mouse wheel as fallback
    document.addEventListener('wheel', function (e) {
        if ($(e.target).closest('.daterangepicker').length) return;
        if (daterangepickerScrolling) return;
        daterangepickerScrolling = true;
        setTimeout(function() {
            hideOpenDateRangePickers();
            daterangepickerScrolling = false;
        }, 50);
    }, { passive: true });

    // Also hide on touch scroll (mobile)
    document.addEventListener('touchmove', function (e) {
        if ($(e.target).closest('.daterangepicker').length) return;
        hideOpenDateRangePickers();
    }, { passive: true });
});
function updateProductQuantityByKeyUp() {
    $('input[name^="qty_"]').on("keyup", function () {
        let qty_elements = $('input[name^="qty_"]');
        let totalQtyCheck = 0;
        let total_qty = 0;
        for (let i = 0; i < qty_elements.length; i++) {
            total_qty += parseInt(qty_elements.eq(i).val());
            totalQtyCheck += qty_elements.eq(i).val();
        }
        $('input[name="current_stock"]').val(total_qty);
        if (totalQtyCheck % 1) {
            toastMagic.warning($("#get-quantity-check-message").data("warning"));
            $(this).val(parseInt($(this).val()));
        }
    });
    $('input[name="current_stock"]').on("keyup", function () {
        if ($(this).val() % 1) {
            toastMagic.warning($("#get-quantity-check-message").data("warning"));
            $(this).val(parseInt($(this).val()));
        }
    });
}

updateProductQuantityByKeyUp();

function reinitializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}



document.querySelectorAll('.no-negative-symbol').forEach(input => {
    input.addEventListener('keydown', function(e) {
        if (e.key === '-' || e.key === 'e') {
            e.preventDefault();
        }
    });

    input.addEventListener('input', function() {
        const cursorPos = this.selectionStart;
        const originalLength = this.value.length;

        this.value = this.value.replace(/-/g, '');
        const newLength = this.value.length;
        this.selectionStart = this.selectionEnd = cursorPos - (originalLength - newLength);
    });
});




let loadMoreBrandOnFilterPage = 2;
$('.load-more-product-brands').on('click', function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        type: 'POST',
        url: $(this).data('route'),
        data: {
            page: loadMoreBrandOnFilterPage,
            filter_brand_old_ids: $('[name="filter_brand_old_ids"]').val(),
        },
        beforeSend: function () {
            $('.load-more-product-brands-spinner').removeClass('d-none');
            $('.load-more-product-brands').addClass('d-none');
        },
        success: function (response) {
            loadMoreBrandOnFilterPage++;
            $("#load-more-filter-brands-view").append(response.html);
            if (response.hiddenCount <= 0) {
                $('.load-more-product-brands-container').hide();
            }

            setTimeout(() => {
                $('.load-more-product-brands-spinner').addClass('d-none');
                $('.load-more-product-brands').removeClass('d-none');
            }, 1000)
        },
        complete: function () {
            setTimeout(() => {
                $('.load-more-product-brands-spinner').addClass('d-none');
                $('.load-more-product-brands').removeClass('d-none');
            }, 1000)
        },
    });
});


let loadMoreStoreOnFilterPage = 2;
$('.load-more-product-stores').on('click', function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        type: 'POST',
        url: $(this).data('route'),
        data: {
            page: loadMoreStoreOnFilterPage,
            filter_shop_ids: $('[name="filter_shop_ids"]').val(),
        },
        beforeSend: function () {
            $('.load-more-product-stores-spinner').removeClass('d-none');
            $('.load-more-product-stores').addClass('d-none');
        },
        success: function (response) {
            loadMoreStoreOnFilterPage++;
            $("#load-more-filter-stores-view").append(response.html);
            if (response.hiddenCount <= 0) {
                $('.load-more-product-stores-container').hide();
            }

            setTimeout(() => {
                $('.load-more-product-stores-spinner').addClass('d-none');
                $('.load-more-product-stores').removeClass('d-none');
            }, 1000)
        },
        complete: function () {
            setTimeout(() => {
                $('.load-more-product-stores-spinner').addClass('d-none');
                $('.load-more-product-stores').removeClass('d-none');
            }, 1000)
        },
    });
});
