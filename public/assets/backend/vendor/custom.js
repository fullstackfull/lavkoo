"use strict";

$(document).ready(function () {
    let checkIsOffcanvasSetupDataValue = $('#check-offcanvas-setup-guide').data('value')?.toString();
    let checkIsOffcanvasSetupGuideEnable = checkIsOffcanvasSetupDataValue === 'true' || checkIsOffcanvasSetupDataValue === '1';

    if (checkIsOffcanvasSetupGuideEnable) {
        setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.delete('offcanvasShow');
            window.history.replaceState({}, document.title, url.toString());
        }, 3000);
    }

    try {
        $(".js-daterangepicker_till_current").daterangepicker({ maxDate: moment() });
    } catch (e) {}
});


$(".show-delete-data-alert").on("click", function () {
    let getText = $("#get-confirm-and-cancel-button-text-for-delete");
    Swal.fire({
        title: $(this).data('alert-title') ?? getText.data("sure"),
        text: $(this).data('alert-text') ?? getText.data("text"),
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

function checkPasswordMatch() {
    const password = $('#newPassword').val();
    const confirmPassword = $('#confirmNewPasswordLabel').val();

    if (confirmPassword.length > 0 && password !== confirmPassword) {
        $('.confirm-password-error').text('Password and confirm password does not match.');
    } else {
        $('.confirm-password-error').text('');
    }
}
$('#newPassword, #confirmNewPasswordLabel').on('keyup change', function () {
    checkPasswordMatch();
});


//category collapse
$(document).on("change", ".category-header input[type='checkbox']", function () {
    let $header = $(this).closest(".category-header");
    let $wrap   = $(this).closest("li");
    let $subList = $wrap.children("ul");

    if ($subList.length === 0) {
        $header.toggleClass("active", $(this).is(":checked"));
        return;
    }

    if ($(this).is(":checked")) {
        $subList.slideDown(200);
        $header.addClass("active");
    }
    else {
        $subList.slideUp(200);
        $header.removeClass("active");

        $subList
            .find("input[type='checkbox']")
            .prop("checked", false)
            .each(function () {
                $(this).closest(".category-header").removeClass("active");
            })
            .trigger("change");
    }
});

$(document).ready(function () {
    // On touch devices (mobile / tablet) keep the trigger `manual` so the
    // click-toggle handler in common-script.js is the only thing showing
    // the tooltip. With `hover`, BS4 fires synthetic mouseover/mouseout on
    // tap and the tooltip flashes shut on finger-lift — only long-press
    // worked because mouseout is delayed during the press. See the matching
    // override in /public/assets/backend/common-script.js.
    var _isTouchOnlyDevice = window.matchMedia && window.matchMedia('(hover: none)').matches;

    $('[data-toggle="tooltip"]').tooltip('dispose');

    $('[data-toggle="tooltip"]').tooltip({
        container: 'body',
        trigger: _isTouchOnlyDevice ? 'manual' : 'hover',
        html: true,
        boundary: 'window'
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


 // Image Modal
$(document).on("click", ".view_btn", function (e) {

    e.preventDefault();
    e.stopImmediatePropagation();
    let $card = $(this).closest(".upload-file, .view-img-wrap");
    let $img = $card.find("img.upload-file-img");

    let actualSrc = $img.attr("data-src") || $img.attr("src");

    if (actualSrc) {
        let $modal = $(".imageModal").first();
        let $modalImg = $modal.find("img.imageModal_img");
        let $downloadBtn = $modal.find(".download_btn");

        $modalImg.attr("src", actualSrc);
        $downloadBtn.attr("href", actualSrc);

        $modal.modal("show");
    }
});

$(document).ready(function() {
    // --- Fixed Action Button ---
    let isFixed = false;

    function checkContentHeight() {
        let windowHeight = $(window).height();
        let contentHeight = $(document).height();
        let scrollPosition = $(window).scrollTop();
        let $actionWrapper = $(".action-btn-wrapper");
        let $actionWrapperContainer = $actionWrapper.closest('.action-btn-wrapper-container');
        let $parent = $actionWrapper.parent();

        setTimeout(() => {
            if (contentHeight > windowHeight) {
                if (!isFixed) {
                    $parent.addClass("fixed-bottom");
                    $actionWrapper.addClass("fixed");
                    $actionWrapperContainer.addClass("fixed");
                    isFixed = true;
                }

                if (scrollPosition + windowHeight >= contentHeight - 250) {
                    if (isFixed) {
                        $actionWrapper.removeClass("fixed");
                        $actionWrapperContainer.removeClass("fixed");
                        $parent.removeClass("fixed-bottom");
                        isFixed = false;
                    }
                }
            } else {
                if (isFixed) {
                    $actionWrapper.removeClass("fixed");
                    $actionWrapperContainer.removeClass("fixed");
                    $parent.removeClass("fixed-bottom");
                    isFixed = false;
                }
            }
        }, 500);
    }

    checkContentHeight();

    $(window).on("resize scroll", function() {
        checkContentHeight();
    });

});

$(".action-input-no-index-event").on("click", function () {
    $(".input-no-index-sub-element").prop("checked", true);
});
