$(".discount-edit-btn").on("click", function () {
    let productId = $(this).data("product-id");
    let id = $(this).data("id");
    let discountAmount = $(this).data("discount-amount");
    let imageUrl = $(this).data("image");
    let productName = $(this).data("product-name");
    let productBrand = $(this).data("product-brand");
    let productCategory = $(this).data("product-category");
    let productStock = $(this).data("product-stock");
    let productType = $(this).data("product-type") ?? "digital";
    let discountType = $(this).data("discount-type");
    let productPrice = $(this).data("unit-price");
    let productShop = $(this).data("shop-name");

    $('#discount-update-modal input[name="discount_amount"]').val(
        discountAmount
    );
    $('#discount-update-modal input[name="product_id"]').val(productId);
    $('#discount-update-modal input[name="id"]').val(id);
    $("#discount-update-modal .modal-body img").attr("src", imageUrl);
    $("#discount-update-modal .modal-body h4").text(productName);
    $("#discount-update-modal .modal-body .modal-product-stock").text(productStock);
    $("#discount-update-modal .modal-body .modal-product-category").text(productCategory);
    $("#discount-update-modal .modal-body .modal-product-brand").text(productBrand);
    $("#discount-update-modal .modal-body .modal-product-price").text(productPrice);
    $("#discount-update-modal .modal-body .modal-product-shop").text(productShop);

    if (productBrand?.toString() === '') {
        $("#discount-update-modal .modal-body span.modal-product-brand-container").hide();
    } else {
        $("#discount-update-modal .modal-body span.modal-product-brand-container").show();
    }

    if (productType?.toString() === 'physical') {
        $('#discount-update-modal .modal-body .modal-product-physical').show();
    } else {
        $('#discount-update-modal .modal-body .modal-product-physical').hide();
    }

    $('#discount-update-modal select[name="discount_type"]')
        .val(discountType)
        .trigger("change");
    var symbol =
        discountType === "percentage"
            ? "(%)"
            : `(${$("#dynamic-currency-symbol").val()})`;
    $("#discount-symbol").html(symbol);
});
