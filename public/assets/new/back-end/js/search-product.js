'use strict';
$('.search-product').on('keyup, input', function () {
    let name = $(this).val().trim();
    if (name.length === 0) {
        $.get($('#get-search-product-route').data('action'), {searchValue: name}, (response) => {
            $('.search-result-box').empty().html(response.result);
        })
        return;
    }
    $.get($('#get-search-product-route').data('action'), {searchValue: name}, (response) => {
        $('.search-result-box').empty().html(response.result);
    });
})

let selectProductSearch = $('.select-product-search');
selectProductSearch.on('click', '.select-product-item', function () {
    let productName = $(this).find('.product-name').text();
    let productId = $(this).find('.product-id').text();
    selectProductSearch.find('button.dropdown-toggle').text(productName);
    $('.product_id').val(productId);
})
$(document).on('keydown', '.select-product-search input', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        return false;
    }
});


$('.search-review-vendor').on('keyup', function () {
    let name = $(this).val();
    $.get($(this).data('route'), {searchValue: name}, (response) => {
        $('.search-review-vendor-result-box').empty().html(response.result);
    })
})


let selectVendorSearch = $('.select-vendor-search');
selectVendorSearch.on('click', '.select-vendor-item', function () {
    let productName = $(this).find('.vendor-name').text();
    let productId = $(this).find('.vendor-id').text();
    selectVendorSearch.find('button.dropdown-toggle').text(productName);
    $('.vendor_id').val(productId);
})
