$(".admin-product-status-form").on("submit", function (event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        url: $(this).attr("action"),
        method: "POST",
        data: $(this).serialize(),
        success: function (response) {
            if (response.status) {
                toastMagic.success(response.message);
            } else {
                toastMagic.error(response.message);
                setTimeout(function () {
                    location.reload();
                }, 2000);
            }
        },
    });
});

$(".action-update-product-quantity").on("click", function () {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    let modalSelector = $(this).data("target");
    $.ajax({
        method: "get",
        url: $(this).data("url"),
        dataType: "json",
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
            $(".rest-part-content").empty().html(response.view);
            $(modalSelector).modal("show");
            updateProductQuantityByKeyUp();
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

$(".action-onclick-reload-page").on("click", function () {
    location.reload();
});

$(".action-select-onchange-get-view").on("change", function () {
    let getUrlPrefix = $(this).data("url-prefix");
    location.href = getUrlPrefix + $(this).val();
});

$(".action-upload-section-dot-area").on("change", function () {
    if (this.files && this.files[0]) {
        let reader = new FileReader();
        reader.onload = () => {
            let imgName = this.files[0].name;
            $(this).closest("[data-title]").attr("data-title", imgName);
        };
        reader.readAsDataURL(this.files[0]);
    }
});

function updateProductQuantity() {
    let elementCurrentStock = $('input[name="current_stock"]');
    let totalQuantity = 0;
    let quantityElements = $('input[name^="qty_"]');
    for (let i = 0; i < quantityElements.length; i++) {
        totalQuantity += parseInt(quantityElements.eq(i).val());
    }
    if (quantityElements.length > 0) {
        elementCurrentStock.attr("readonly", true);
        elementCurrentStock.val(totalQuantity);
    } else {
        elementCurrentStock.attr("readonly", false);
    }
}

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

function getRequestFunctionalityRender() {
    $(".action-get-request-onchange").on("change", function () {
        let getUrlPrefix = $(this).data("url-prefix") + $(this).val();
        let id = $(this).data("element-id");
        let getElementType = $(this).data("element-type");
        getRequestFunctionality(getUrlPrefix, id, getElementType);
    });
}
getRequestFunctionalityRender();

function getRequestFunctionality(getUrlPrefix, id, getElementType) {
    let message = $("#message-select-word").data("text");
    $("#sub-sub-category-select")
        .empty()
        .append(
            `<option value="null" selected disabled>---` +
                message +
                `---</option>`
        );

    $.get({
        url: getUrlPrefix,
        dataType: "json",
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (data) {
            if (getElementType === "select") {
                $("#" + id)
                    .empty()
                    .append(data.select_tag);
                if (
                    data.sub_categories !== "" &&
                    id.toString() === "sub-category-select"
                ) {
                    let nextElement = $("#" + id).data("element-id");
                    $("#" + nextElement)
                        .empty()
                        .append(data.sub_categories);
                }
            }
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
}

$(".update-status").on("click", function () {
    let id = $(this).data("id");
    let status = $(this).data("status");
    let getText = $("#get-confirm-and-cancel-button-text");
    let targetUrl = $(this).data("redirect-route");
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
            $.ajaxSetup({
                headers: {
                    "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
                },
            });
            $.ajax({
                url: $("#get-update-status-route").data("action"),
                method: "POST",
                data: {
                    id: id,
                    status: status,
                },
                success: function (response) {
                    toastMagic.success(response.message);
                    setTimeout(() => {
                        if (targetUrl) {
                            location.href = targetUrl;
                        } else {
                            location.reload();
                        }
                    }, 2000)
                },
            });
        }
    });
});
