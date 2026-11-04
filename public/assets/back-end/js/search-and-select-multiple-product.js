'use strict';
$('.search-product').on('keyup',function (){
    let name = $(this).val();
    if (name.length > 0) {
        $.get($('#get-search-product-route').data('action'), {searchValue: name}, (response) => {
            $('.search-result-box').empty().html(response.result);
        })
    }
})
let searchTimeout;
// Listen on `input` only (no longer on `click focus`). The search input
// now lives INSIDE a Bootstrap-5 dropdown whose own toggle button opens
// the menu — focusing the input on dropdown-open used to fire the empty
// branch and immediately remove `.show`, closing the menu the user just
// opened. Typing still drives the search; clearing back to empty just
// resets the result list and lets BS5 manage the dropdown state.
$(document).on('input', '.search-all-type-product', function () {
    let name = $(this).val();
    let dealId = $(this).data('deal-id');
    let $input = $(this);
    let $resultBox = $input.closest('.dropdown').find('.search-result-box');

    clearTimeout(searchTimeout);

    if (name.length > 0) {
        searchTimeout = setTimeout(function() {
            let data = { searchValue: name };
            if (dealId) {
                data.deal_id = dealId;
            }
            $.ajax({
                url: $('#get-search-all-type-product-route').data('action'),
                type: 'GET',
                data: data,
                beforeSend: function () {
                    $resultBox.html(searchLoaderMarkup());
                },
                success: function (response) {
                    $resultBox.empty().html(response.result);
                },
                error: function () {
                    $resultBox.empty();
                }
            });
        }, 500);
    } else {
        $resultBox.empty();
    }
});

// Inline loader shown inside the dropdown's result box while a product
// search request is in flight. Scoped to the current dropdown so a
// search on the flash-deal page never blanks out the clearance-sale
// dropdown (and vice versa). The site already loads bootstrap-icons /
// uicons; using fi-rr-spinner with a simple spin animation keeps the
// dependency footprint zero.
function searchLoaderMarkup() {
    return '<div class="d-flex justify-content-center align-items-center py-4 product-search-loader">'
         + '<div class="spinner-border spinner-border-sm text-primary me-2" role="status" aria-hidden="true"></div>'
         + '<span class="text-muted fs-12">' + (window.searchLoaderText || 'Searching...') + '</span>'
         + '</div>';
}

// Shared toast for "this product is already in your selection" — both
// the flash/featured deal click handler and the clearance-sale handler
// call this when the clicked product is already in the selection array.
// The translated message comes from a hidden #product-already-selected-message
// span rendered by the blade so the copy stays localized.
function showAlreadySelectedToast() {
    if (typeof toastMagic === 'undefined' || !toastMagic.warning) return;
    let msg = $('#product-already-selected-message').data('message') || 'Product is already selected';
    toastMagic.warning(msg);
}

// Auto-focus the search input the moment the dropdown opens — saves
// the user a second click. BS5 fires `shown.bs.dropdown` on the toggle
// element after the menu finishes its open animation; we listen via
// document delegation so this works for any wrapper that uses our
// product-search shell.
$(document).on('shown.bs.dropdown', '.select-product-search, .select-clearance-product-search', function () {
    let $input = $(this).find('.search-all-type-product, .search-product-for-clearance-sale').first();
    if ($input.length) $input.trigger('focus');
});

// Close the product-search dropdown right after a click (or after
// showing the already-selected toast). The toggle button carries
// data-bs-auto-close="outside" so BS5 won't auto-close on inside
// clicks — we drive it explicitly via the BS5 vanilla Dropdown API
// (admin runs BS5 only; jQuery .dropdown('hide') sugar doesn't exist).
// Falls back to stripping the `.show` class on the menu directly if
// the BS5 instance can't be resolved.
function closeProductSearchDropdown($wrapper) {
    if (!$wrapper || !$wrapper.length) return;
    let toggle = $wrapper.find('[data-bs-toggle="dropdown"]')[0];
    if (toggle && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
        let instance = bootstrap.Dropdown.getOrCreateInstance(toggle);
        if (instance) {
            instance.hide();
            return;
        }
    }
    $wrapper.find('.dropdown-menu').removeClass('show');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
}

$(document).ready(function () {
    let searchTimeout;
    // `input` only (was `input focus keyup`). The clearance-sale search
    // input now sits inside a BS5 dropdown opened via a separate toggle
    // button — focusing the input on dropdown-open used to fire the empty
    // branch and immediately remove `.show`, snapping the menu shut.
    // BS5's own auto-close handles outside clicks; we only need to drive
    // the result list as the user types.
    $(document).on('input', '.search-product-for-clearance-sale', function () {
        let $input = $(this);
        let $wrapper = $input.closest('.select-clearance-product-search');
        let $resultBox = $wrapper.find('.search-result-box');
        let name = $input.val();
        clearTimeout(searchTimeout);
        if (name.length > 0) {
            searchTimeout = setTimeout(function() {
                $.ajax({
                    url: $('#get-search-product-for-clearance-route').data('action'),
                    type: 'GET',
                    data: { searchValue: name },
                    beforeSend: function () {
                        $resultBox.html(searchLoaderMarkup());
                    },
                    success: function (response) {
                        $wrapper.find('.dropdown-menu').addClass('show')
                        $resultBox.html(response.result);
                    },
                    error: function () {
                        $resultBox.empty();
                    }
                });
            }, 500);
        } else {
            $wrapper.find('.dropdown-menu').removeClass('show')
            $resultBox.empty();
        }
    });
    // Outside-click closes our two custom product-search dropdowns. Only
    // strip `.show` from THESE wrappers' menus — touching every
    // .dropdown-menu on the page slammed Bootstrap's own dropdowns shut
    // (including the new BS5 toggle on the flash/featured deal page,
    // which has no .select-clearance-product-search at all, so EVERY
    // click was treated as "outside" and removed `.show`).
    $(document).on('click', function (e) {
        let $target = $(e.target);
        if (!$target.closest('.select-clearance-product-search').length) {
            $('.select-clearance-product-search .dropdown-menu').removeClass('show');
        }
    });
    $(document).on('keypress', '.search-product-for-clearance-sale', function (e) {
        if (e.which === 13) {
            e.preventDefault();
        }
    });
});

let productIdsArray = [];
// Delegate to document so the handler works regardless of when the
// `.select-product-search` wrapper enters the DOM (e.g. partials
// rendered late, modals reopened, etc).
$(document).on('click', '.select-product-search .select-product-item', function () {
    let $item = $(this);
    let productId = $item.find('.product-id').text();
    if (!productId) return;
    // (Bug fix: the original `if (indexOf(...))` check failed for index
    // 0 — falsy — so the very first product silently never got added.)
    if (productIdsArray.indexOf(productId) === -1) {
        productIdsArray.push(productId);
        getProductDetails(productIdsArray);
    } else {
        // Selection state survives modal close/reopen because the array
        // lives at module scope. Warn the user instead of silently doing
        // nothing when they pick the same product again.
        showAlreadySelectedToast();
    }
    // Close the dropdown after each pick — user wants a one-click flow:
    // open dropdown, choose product, dropdown collapses. They can reopen
    // it again to add another. We can't rely on Bootstrap's auto-close
    // because the toggle has data-bs-auto-close="outside" so multi-add
    // could work; close it explicitly here instead.
    closeProductSearchDropdown($item.closest('.dropdown'));
})
function removeSelectedProduct(){
    $('.remove-selected-product').on('click', function () {
        let removedId = String($(this).data('product-id'));
        // splice only removes from `start` if no `deleteCount` is given;
        // pass 1 so we drop just this one entry instead of every entry
        // from the index onward.
        let idx = productIdsArray.indexOf(removedId);
        if (idx !== -1) productIdsArray.splice(idx, 1);
        $(this).closest('.select-product-item').remove();
    });
}
$('.reset-selected-products').on('click',function (){
    productIdsArray = [];
    $('#selected-products').empty();
})

function getProductDetails(productIds){
    $.ajax({
        url: $('#get-multiple-product-details-route').data('action'),
        type: 'GET',
        data: { productIds: productIds },
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function(response) {
            $('#selected-products').empty().html(response.result);

            removeSelectedProduct();
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });

}

let clearanceProductIdsArray = [];
// Delegate to document — the clearance-sale add-product modal isn't
// always in the DOM at script-load time, and even when it is, capturing
// `$('.select-clearance-product-search')` once at load means a re-render
// of the modal/search list breaks the binding. Document delegation is
// stable across re-renders.
$(document).on('click', '.select-clearance-product-search .select-clearance-product-item', function () {
    let $item = $(this);
    let productId = $item.find('.product-id').text();
    if (!productId) return;
    if (clearanceProductIdsArray.indexOf(productId) === -1) {
        clearanceProductIdsArray.push(productId);
        getClearanceProductDetails(clearanceProductIdsArray);
    } else {
        // The modal can be closed and reopened — clearanceProductIdsArray
        // survives, so picking the same product again would silently do
        // nothing without this branch.
        showAlreadySelectedToast();
    }
    checkClearanceProductArray()
    closeProductSearchDropdown($item.closest('.dropdown'));
})

function removeSelectedClearanceProduct() {
    $('.remove-selected-clearance-product').on('click', function () {
        let removedId = String($(this).data('product-id'));
        let idx = clearanceProductIdsArray.indexOf(removedId);
        if (idx !== -1) clearanceProductIdsArray.splice(idx, 1);
        $(this).closest('.remove-selected-clearance-parent').remove();
        checkClearanceProductArray()
    });
}

function getClearanceProductDetails(productIds) {
    $.ajax({
        url: $('#get-multiple-clearance-product-details-route').data('action'),
        type: 'GET',
        data: {productIds: productIds},
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
            $('#selected-products').empty().html(response.result);
            removeSelectedClearanceProduct();
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });

}

function checkClearanceProductArray() {
    if (clearanceProductIdsArray?.length > 0) {
        $('.search-and-add-product').hide();
    } else {
        $('.search-and-add-product').show();
    }
}

$(document).ready(function() {
    const $selectedProductsContainer = $('#selected-products');
    const $addProductsBtn = $('#add-products-btn');
    function toggleAddProductsButton() {
        $addProductsBtn.prop('disabled', $selectedProductsContainer.children().length === 0);
    }

    toggleAddProductsButton();

    const observer = new MutationObserver(toggleAddProductsButton);
    observer.observe($selectedProductsContainer[0], { childList: true });
});
