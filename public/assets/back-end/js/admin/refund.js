"use strict";

$(document).ready(function () {

    $('#approved').hide();
    $("#approved_note").prop("required", false);
    $('#rejected').hide();
    $("#rejected_note").prop("required", false);
    $('#payment_option').hide();
    $("#payment_method").prop("required", false);
    $('#refunded').hide();
    $("#payment_info").prop("required", false);
});

$("#refund_status_change").on('change', function () {
    let value = $(this).val();
    if (value === 'approved') {
        $('#rejected').hide();
        $("#rejected_note").prop("required", false);
        $('#refunded').hide();
        $("#payment_info").prop("required", false);
        $('#payment_option').hide();
        $("#payment_method").prop("required", false);

        $('#approved').show();
        $("#approved_note").prop("required", true);

    } else if (value === 'rejected') {
        $('#approved').hide();
        $("#approved_note").prop("required", false);
        $('#refunded').hide();
        $("#payment_info").prop("required", false);
        $('#payment_option').hide();
        $("#payment_method").prop("required", false);

        $('#rejected').show();
        $("#rejected_note").prop("required", true);

    } else if (value === 'refunded') {
        Swal.fire({
            title: $('#message-alert-title').data('text'),
            type: 'warning',
        });

        $('#approved').hide();
        $("#approved_note").prop("required", false);
        $('#rejected').hide();
        $("#rejected_note").prop("required", false);

        $('#refunded').show();
        $("#payment_info").prop("required", true);
        $('#payment_option').show();
        $("#payment_method").prop("required", true);
    } else {
        $('#approved').hide();
        $("#approved_note").prop("required", false);
        $('#rejected').hide();
        $("#rejected_note").prop("required", false);

        $('#refunded').hide();
        $("#payment_info").prop("required", false);
        $('#payment_option').hide();
        $("#payment_method").prop("required", false);
    }
});

function imageSlider() {
    $(document).on(
        "click",
        '[data-bs-target^="#imgViewModal"]',
        function() {
            var modalId = $(this).attr("data-bs-target");
            var $modal = $(modalId);
            var $carousel = $modal.find(".imgView-slider");

            var slideCount = $modal.find(".imgView-item").length;

            // Destroy existing Owl Carousel
            if ($carousel.hasClass("owl-loaded")) {
                $carousel
                    .trigger("destroy.owl.carousel")
                    .removeClass("owl-loaded");
                $carousel.html($carousel.find(".owl-stage-outer").html());
            }

            // Init Owl Carousel
            var imgView = $carousel.owlCarousel({
                items: 1,
                loop: false,
                margin: 0,
                nav: false,
                dots: false,
                mouseDrag: slideCount > 1,
                touchDrag: slideCount > 1,
                autoplay: false,
                smartSpeed: 500,
                onChanged: function(event) {
                    var currentIndex = event.item.index;
                    $modal
                        .find(".imgView-owl-prev")
                        .prop("disabled", currentIndex === 0)
                        .toggleClass("disabled", currentIndex === 0);
                    $modal
                        .find(".imgView-owl-next")
                        .prop("disabled", currentIndex === slideCount - 1)
                        .toggleClass(
                            "disabled",
                            currentIndex === slideCount - 1
                        );
                }
            });

            // Show/hide nav buttons
            if (slideCount <= 1) {
                $modal.find(".imgView-owl-prev, .imgView-owl-next").hide();
            } else {
                $modal.find(".imgView-owl-prev, .imgView-owl-next").show();

                $modal
                    .find(".imgView-owl-prev")
                    .off("click")
                    .on("click", function() {
                        imgView.trigger("prev.owl.carousel");
                    });

                $modal
                    .find(".imgView-owl-next")
                    .off("click")
                    .on("click", function() {
                        imgView.trigger("next.owl.carousel");
                    });
            }

            // Go to specific slide
            var index = $(this).data("index");
            if (slideCount > 1) {
                imgView.trigger("to.owl.carousel", [index, 0]);
            }

            // Set image titles
            $modal.find(".imgView-item").each(function() {
                var imgSrc = $(this)
                    .find("img")
                    .attr("src");
                if (imgSrc) {
                    var imgTitle = imgSrc.split("/").pop();
                    $(this)
                        .find(".img-title")
                        .text(imgTitle);
                }
            });
        }
    );
}
imageSlider();

function checkImageOverflow() {
    $(".refund-image-wrapper").each(function () {
        let $nav = $(this);
        let $wrapper = $nav.closest(".position-relative");
        let $btnNext = $wrapper.find(".next_btn");
        let $btnPrev = $wrapper.find(".prev_btn");

        let isRTL = $("html").attr("dir") === "rtl";
        let nav = $nav[0];

        let navScrollWidth = nav.scrollWidth;
        let navClientWidth = nav.clientWidth;
        let scrollLeft = Math.abs($nav.scrollLeft());

        if (isRTL) {
            let maxScrollLeft = navScrollWidth - navClientWidth;
            let scrollRight = maxScrollLeft - scrollLeft;

            $btnNext.toggle(scrollRight > 1);
            $btnPrev.toggle(scrollLeft > 1);
        } else {
            $btnNext.toggle(
                navScrollWidth > navClientWidth &&
                scrollLeft + navClientWidth < navScrollWidth - 1
            );
            $btnPrev.toggle(scrollLeft > 1);
        }
    });
}

$(".refund-image-wrapper").each(function () {
    let $nav = $(this);
    let $wrapper = $nav.closest(".position-relative");
    let $btnNext = $wrapper.find(".next_btn");
    let $btnPrev = $wrapper.find(".prev_btn");

    // Initial check
    checkImageOverflow();

    // Resize
    $(window).on("resize", function () {
        checkImageOverflow();
    });

    // On scroll
    $nav.on("scroll", function () {
        checkImageOverflow();
    });

    let scrollAmount = $nav.find("a").outerWidth(true);

    $btnNext.on("click", function () {
        let isRTL = $("html").attr("dir") === "rtl";

        $nav.animate(
            {
                scrollLeft: isRTL
                    ? $nav.scrollLeft() - scrollAmount
                    : $nav.scrollLeft() + scrollAmount
            },
            300
        );
    });

    $btnPrev.on("click", function () {
        let isRTL = $("html").attr("dir") === "rtl";

        $nav.animate(
            {
                scrollLeft: isRTL
                    ? $nav.scrollLeft() + scrollAmount
                    : $nav.scrollLeft() - scrollAmount
            },
            300
        );
    });
});