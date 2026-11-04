$(document).ready(function() {
    // --- Toggle Switch ---
    $(".switcher_input_controllable").each(function() {
        let inputField = $(this)
            .closest(".form-group")
            .find(".form-control");
        let storedValue = inputField.val().trim();
        let originalPlaceholder = inputField.attr("placeholder");

        inputField.data("original-placeholder", originalPlaceholder);

        if (storedValue !== "") {
            inputField.prop("disabled", true);
        }
    });

    $(".switcher_input_controllable").on("change", function() {
        let inputField = $(this)
            .closest(".form-group")
            .find(".form-control");
        let storedValue = inputField.val().trim();
        let originalPlaceholder = inputField.data("original-placeholder");

        if ($(this).is(":checked")) {
            if (storedValue !== "") {
                inputField.prop("disabled", false);
            } else {
                inputField
                    .prop("disabled", false)
                    .attr("placeholder", "")
                    .focus();
            }
        } else {
            inputField.prop("disabled", true);
            if (!storedValue) {
                inputField.attr("placeholder", originalPlaceholder);
            }
        }
    });

    // --- Textarea Max Length ---
    $("textarea.form-control, input.form-control").each(function() {
        let maxLength = $(this).data("maxlength");
        let currentLength = $(this).val().length;

        // Ensure the value doesn't exceed maxLength
        if (currentLength > maxLength) {
            $(this).val(
                $(this)
                    .val()
                    .substring(0, maxLength)
            );
            currentLength = maxLength;
        }

        $(this)
            .closest(".form-group")
            .find("span.text-body-light")
            .text(currentLength + "/" + maxLength);
    });

    $("textarea.form-control, input.form-control").on("input", function() {
        let maxLength = $(this).data("maxlength");
        let currentLength = $(this).val().length;

        if (currentLength > maxLength) {
            $(this).val(
                $(this)
                    .val()
                    .substring(0, maxLength)
            ); // Ensure text is trimmed
            currentLength = maxLength;
        }

        $(this)
            .closest(".form-group")
            .find("span.text-body-light")
            .text(currentLength + "/" + maxLength);
    });

    // --- Password Show/Hide ---
    $(
        "#changePassTarget, #changeConfirmPassTarget, .changePassTarget, #changeConfirmPassTarget"
    ).on("click", function() {
        let passwordInput = $(this).siblings("input.form-control");
        let icon = $(this).find("i");

        if (passwordInput.attr("type") === "password") {
            passwordInput.attr("type", "text");
            icon.removeClass("fi-sr-eye").addClass("fi-sr-eye-crossed");
        } else {
            passwordInput.attr("type", "password");
            icon.removeClass("fi-sr-eye-crossed").addClass("fi-sr-eye");
        }
    });

    // --- Changing svg color ---
    $("img.svg").each(function() {
        var $img = jQuery(this);
        var imgID = $img.attr("id");
        var imgClass = $img.attr("class");
        var imgURL = $img.attr("src");

        jQuery.get(
            imgURL,
            function(data) {
                var $svg = jQuery(data).find("svg");

                if (typeof imgID !== "undefined") {
                    $svg = $svg.attr("id", imgID);
                }
                if (typeof imgClass !== "undefined") {
                    $svg = $svg.attr("class", imgClass + " replaced-svg");
                }

                $svg = $svg.removeAttr("xmlns:a");

                if (
                    !$svg.attr("viewBox") &&
                    $svg.attr("height") &&
                    $svg.attr("width")
                ) {
                    $svg.attr(
                        "viewBox",
                        "0 0 " + $svg.attr("height") + " " + $svg.attr("width")
                    );
                }
                $img.replaceWith($svg);
            },
            "xml"
        );
    });

    // --- Tab Menu ---
    function checkNavOverflow() {
        try {
            $(".nav--tab").each(function() {
                let $nav = $(this);
                let $btnNext = $nav
                    .closest(".position-relative")
                    .find(".nav--tab__next");
                let $btnPrev = $nav
                    .closest(".position-relative")
                    .find(".nav--tab__prev");
                let isRTL = $("html").attr("dir") === "rtl";
                let navScrollWidth = $nav[0].scrollWidth;
                let navClientWidth = $nav[0].clientWidth;
                let scrollLeft = Math.abs($nav.scrollLeft());

                if (isRTL) {
                    let maxScrollLeft = navScrollWidth - navClientWidth;
                    let scrollRight = maxScrollLeft - scrollLeft;

                    $btnNext.toggle(scrollRight > 1);
                    $btnPrev.toggle(scrollLeft > 1);
                } else {
                    $btnNext.toggle(
                        navScrollWidth > navClientWidth &&
                            scrollLeft + navClientWidth < navScrollWidth
                    );
                    $btnPrev.toggle(scrollLeft > 1);
                }
            });
        } catch (error) {
            console.error(error);
        }
    }

    $(".nav--tab").each(function() {
        let $nav = $(this);
        let $activeItem = $nav.find(".nav-link.active");

        if ($activeItem.length) {
            let isRTL = $("html").attr("dir") === "rtl";
            let nav = $nav[0];
            let activeItem = $activeItem[0];

            let navRect = nav.getBoundingClientRect();
            let itemRect = activeItem.getBoundingClientRect();

            let offset = itemRect.left - navRect.left - 60;

            nav.scrollLeft += offset;

            checkNavOverflow($nav);
        }

        $(window).on("resize", function() {
            checkNavOverflow($nav);
        });

        $nav.on("scroll", function() {
            checkNavOverflow($nav);
        });

        $nav.siblings(".nav--tab__next").on("click", function() {
            let scrollWidth = $nav.find("li").outerWidth(true);
            let isRTL = $("html").attr("dir") === "rtl";

            if (isRTL) {
                $nav.animate(
                    { scrollLeft: $nav.scrollLeft() - scrollWidth },
                    300,
                    function() {
                        checkNavOverflow($nav);
                    }
                );
            } else {
                $nav.animate(
                    { scrollLeft: $nav.scrollLeft() + scrollWidth },
                    300,
                    function() {
                        checkNavOverflow($nav);
                    }
                );
            }
        });

        $nav.siblings(".nav--tab__prev").on("click", function() {
            let scrollWidth = $nav.find("li").outerWidth(true);
            let isRTL = $("html").attr("dir") === "rtl";

            if (isRTL) {
                $nav.animate(
                    { scrollLeft: $nav.scrollLeft() + scrollWidth },
                    300,
                    function() {
                        checkNavOverflow($nav);
                    }
                );
            } else {
                $nav.animate(
                    { scrollLeft: $nav.scrollLeft() - scrollWidth },
                    300,
                    function() {
                        checkNavOverflow($nav);
                    }
                );
            }
        });
    });

    // --- Fixed Action Button ---
    initActionButton();

    // --- TagsInput ---
    if ($.fn.tagsinput) {
        $(".bootstrap-tags-input").each(function() {
            let $input = $(this);

            $input.tagsinput({
                confirmKeys: [13, 44], // Enter & Comma
                trimValue: true
            });

            $input.on("itemAdded itemRemoved", function() {
                $(this).val(
                    $(this)
                        .tagsinput("items")
                        .join(",")
                );
            });
        });
    } else {
        console.warn("Bootstrap Tags Input plugin not loaded");
    }

    // --- ColorpickerInput Text dynamic ---
    $(".form-control_color").on("input", function() {
        let selectedColor = $(this).val();
        $(this)
            .siblings(".color-code")
            .text(selectedColor);
    });

    // --- div show/hide with view button ---
    $(".view-btn").each(function() {
        var container = $(this).closest(".view-details-container");
        var details = container.find(".view-details");
        var icon = $(this).find("i");

        if ($(this).hasClass("active")) {
            icon.addClass("rotate-180deg");
            details.show();
        } else {
            details.hide();
        }
    });

    $(".view-btn").on("click", function() {
        var container = $(this).closest(".view-details-container");
        var details = container.find(".view-details");
        var icon = $(this).find("i");

        $(this).toggleClass("active");
        details.slideToggle(300);
        icon.toggleClass("rotate-180deg");
    });

    // --- lightbox ---
    $(".lightbox_custom").on("click", function(e) {
        e.preventDefault();
        new lightbox(this);
    });

    // --- file upload with preview ---;
    $(".image-preview-before-upload").on("change", function() {
        let getElementId = $(this).data("preview");
        console.log("Preview selector:", getElementId);

        if (this.files && this.files[0]) {
            let file = this.files[0];

            if (file.type.startsWith("image/")) {
                let fileURL = URL.createObjectURL(file);
                $(getElementId)
                    .attr("src", fileURL)
                    .removeClass("d-none");
            } else {
                alert("Please select a valid image file.");
            }
        }
    });

    $("form").on("reset", function() {
        let placeholder = $("#viewer").data("placeholder");
        $("#viewer").attr("src", placeholder);
    });

    // --- Print Invoice ---
    $(".action-print-invoice").on("click", function() {
        printDiv($(this).data("value"));
    });

    function printDiv(divName) {
        let printContents = document.getElementById(divName).innerHTML;
        let originalContents = document.body.innerHTML;
        document.body.innerHTML = printContents;
        window.print();
        document.body.innerHTML = originalContents;
    }

    // --- Product Gallery View more ---
    $(".view--more").each(function() {
        const viewItem = $(this);
        const initialHeight = $(this).height();
        if (viewItem.height() > 130) {
            viewItem.addClass("view-more-collapsable");
            const btn = viewItem.find(".expandable-btn");
            btn.removeClass("d-none");
            btn.on("click", function() {
                if (btn.find(".more").hasClass("d-none")) {
                    viewItem.css("height", "130px");
                    btn.find(".more").removeClass("d-none");
                    btn.find(".less").addClass("d-none");
                } else {
                    viewItem.css("height", initialHeight + 40);
                    btn.find(".less").removeClass("d-none");
                    btn.find(".more").addClass("d-none");
                }
            });
        }
    });

    // document ready fn end
});

// --- Toggle Modal ---
$(".toggle-switch-dynamic-image").on("click", function(event) {
    event.preventDefault();
    const modalId = $(this).data("modal-id");
    const toggleId = $(this).data("toggle-id");
    const onImage = $(this).data("on-image");
    const offImage = $(this).data("off-image");
    const onTitle = $(this).data("on-title");
    const offTitle = $(this).data("off-title");
    const onMessage = $(this).data("on-message");
    const offMessage = $(this).data("off-message");
    toggleModal(
        modalId,
        toggleId,
        onImage,
        offImage,
        onTitle,
        offTitle,
        onMessage,
        offMessage
    );
});

$(document).on("click", ".toggle-switch-message", function(event) {
    event.preventDefault();
    let rootPath = $("#get-root-path-for-toggle-modal-image").data("path");
    const modalId = $(this).data("modal-id");
    const toggleId = $(this).data("toggle-id");
    const onImage = rootPath + "/" + $(this).data("on-image");
    const offImage = rootPath + "/" + $(this).data("off-image");
    const onTitle = $(this).data("on-title");
    const offTitle = $(this).data("off-title");
    const onMessage = $(this).data("on-message");
    const offMessage = $(this).data("off-message");
    const onBtnText = $(this).data("on-button-text");
    const offBtnText = $(this).data("off-button-text");
    const noBtnText = $(this).data("no-button-text");
    const verification = $(this).data("verification");

    if (verification && verification === "firebase-auth") {
        try {
            if (checkFirebaseAuthVerification()) {
                toggleModal(
                    modalId,
                    toggleId,
                    onImage,
                    offImage,
                    onTitle,
                    offTitle,
                    onMessage,
                    offMessage
                );
            }
        } catch (e) {
            console.log(e);
        }
    } else {
        toggleModal(
            modalId,
            toggleId,
            onImage,
            offImage,
            onTitle,
            offTitle,
            onMessage,
            offMessage,
            onBtnText,
            offBtnText,
            noBtnText
        );
    }
});

function toggleModal(
    modalId,
    toggleId,
    onImage = null,
    offImage = null,
    onTitle,
    offTitle,
    onMessage,
    offMessage,
    onBtnText = "Yes, on",
    offBtnText = "Yes, off",
    noBtnText = "Cancel"
) {
    if ($("#" + toggleId).is(":checked")) {
        $("#" + modalId + "-title")
            .empty()
            .append(onTitle);
        $("#" + modalId + "-message")
            .empty()
            .append(onMessage);
        $("." + modalId + "-button-text")
            .empty()
            .append(onBtnText);
        $("." + modalId + "-no-button-text")
            .empty()
            .append(noBtnText);
        $("#" + modalId + "-image").attr("src", onImage);
        $("#" + modalId + "-ok-button").attr("toggle-ok-button", toggleId);
    } else {
        $("#" + modalId + "-title")
            .empty()
            .append(offTitle);
        $("#" + modalId + "-message")
            .empty()
            .append(offMessage);
        $("." + modalId + "-button-text")
            .empty()
            .append(offBtnText);
        $("." + modalId + "-no-button-text")
            .empty()
            .append(noBtnText);
        $("#" + modalId + "-image").attr("src", offImage);
        $("#" + modalId + "-ok-button").attr("toggle-ok-button", toggleId);
    }
    $("#" + modalId).modal("show");
}
function ajaxSetupToken() {
    $.ajaxSetup({
        headers: {
            "X-XSRF-TOKEN": $('meta[name="csrf-token"]').attr("content")
        }
    });
}
function checkFirebaseAuthVerification() {
    var firebaseAuthVerification = true;
    let checkbox = $(".firebase-auth-verification");
    if (checkbox.prop("checked")) {
        ajaxSetupToken();
        $.post({
            url: checkbox.data("route"),
            data: {
                _token: $('meta[name="csrf-token"]').attr("content"),
                key: checkbox.data("key")
            },
            async: false,
            beforeSend: function() {},
            success: function(response) {
                if (response?.status === false) {
                    $("#firebaseAuthConfigValidation .modal-body")
                        .empty()
                        .html(response?.htmlView);
                    $("#firebaseAuthConfigValidation").modal("show");
                    firebaseAuthVerification = false;
                } else {
                    checkbox.prop("checked", !checkbox.prop("checked"));
                }
            },
            complete: function() {}
        });
    }
    return firebaseAuthVerification;
}
$("#toggle-modal-ok-button").on("click", function() {
    const toggleIdName = $(this).attr("toggle-ok-button");
    const toggleId = $("#" + $(this).attr("toggle-ok-button"));
    if (toggleId.is(":checked")) {
        toggleId.prop("checked", false);
    } else {
        toggleId.prop("checked", true);
    }
    $("#toggle-modal").modal("hide");
    if (toggleIdName === "email-verification") {
        if (
            $("#email-verification").is(":checked") &&
            $("#otp-verification").is(":checked")
        ) {
            $("#otp-verification").removeAttr("checked");
            toastMagic.info(
                $("#get-email-and-otp-verification-info-message").data("info")
            );
        }
    }
    if (
        toggleIdName === "otp-verification" &&
        $("#get-application-environment-mode").data("value") !== "demo"
    ) {
        if (
            $("#otp-verification").is(":checked") &&
            $("#email-verification").is(":checked")
        ) {
            $("#email-verification").removeAttr("checked");
            toastMagic.info(
                $("#get-email-and-otp-verification-info-message").data("info")
            );
        }
    }
});

$(".call-demo-alert").on('click', function () {
    callDemoAlert();
})

function callDemoAlert() {
    toastMagic.info(
        $("#call-demo-message").data("title"),
        $("#call-demo-message").data("text"),
        true
    );
}

function checkAlternativeCheckbox(toggleIdName) {
    if (toggleIdName === "storage-connection-s3") {
        let storageConnectionS3 = $("#storage-connection-s3");
        let storageConnectionLocal = $("#storage-connection-local");
        if (storageConnectionS3.is(":checked")) {
            storageConnectionLocal.removeAttr("checked");
        } else {
            storageConnectionLocal.prop("checked", true);
        }
    }

    if (toggleIdName === "storage-connection-local") {
        let storageConnectionS3 = $("#storage-connection-s3");
        let storageConnectionLocal = $("#storage-connection-local");
        if (storageConnectionLocal.is(":checked")) {
            storageConnectionS3.removeAttr("checked");
        } else {
            storageConnectionS3.prop("checked", true);
        }
    }
}

$("#toggle-status-modal-ok-button").on("click", function() {
    const toggleId = $("#" + $(this).attr("toggle-ok-button"));
    if (toggleId.is(":checked")) {
        toggleId.prop("checked", false);
    } else {
        toggleId.prop("checked", true);
    }
    let toggleOkButton = $(this).attr("toggle-ok-button") + "-form";
    checkAlternativeCheckbox($(this).attr("toggle-ok-button"));
    submitStatusUpdateForm(toggleOkButton);
});
$("#toggle-status-custom-modal-ok-button").on("click", function() {
    const toggleId = $("#" + $(this).attr("toggle-ok-button"));
    if (toggleId.is(":checked")) {
        toggleId.prop("checked", false);
    } else {
        toggleId.prop("checked", true);
    }
    let toggleOkButton = $(this).attr("toggle-ok-button") + "-form";
    checkAlternativeCheckbox($(this).attr("toggle-ok-button"));
    submitStatusUpdateForm(toggleOkButton);
});

$("#toggle-new-modal-ok-button").on("click", function() {
    const toggleId = $("#" + $(this).attr("toggle-ok-button"));
    if (toggleId.is(":checked")) {
        toggleId.prop("checked", false);
    } else {
        toggleId.prop("checked", true);
    }
    let toggleOkButton = $(this).attr("toggle-ok-button") + "-form";
    checkAlternativeCheckbox($(this).attr("toggle-ok-button"));
    submitStatusUpdateForm(toggleOkButton);
});

// --- Redirect link and close modal logic ---
function closeModalAndRedirect(element) {
    let modal = bootstrap.Modal.getInstance(element.closest(".modal"));
    if (modal) {
        modal.hide();
    }
    setTimeout(() => {
        window.location.href = element.href;
    }, 300);
    return false;
}

// --- Swiper Instruction slider ---
$(window).on("load", function() {
    if ($(".instruction-carousel").length) {
        let slideCount = $(".instruction-carousel .swiper-slide").length;
        let swiperPaginationCustom = $(".instruction-pagination-custom");
        let swiperPaginationAll = $(
            ".instruction-pagination-custom, .instruction-pagination"
        );
        swiperPaginationCustom.html(`1 / ${slideCount}`);

        var swiper = new Swiper(".instruction-carousel", {
            autoHeight: true,
            pagination: {
                el: ".instruction-pagination",
                clickable: true
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev"
            },
            on: {
                slideChange: () => {
                    swiperPaginationCustom.html(
                        `${swiper.realIndex + 1} / ${swiper.slidesGrid.length}`
                    );
                    if (swiper.isEnd) {
                        swiperPaginationAll.css("display", "none");
                    } else {
                        swiperPaginationAll.css("display", "block");
                    }
                }
            }
        });
    }
});

// ---- copy to clipboard ---
$(".copy-to-clipboard").on("click", function() {
    let copiedText = $($(this).data("id")).text();
    let tempInput = $("<textarea>");
    $("body").append(tempInput);
    tempInput.val(copiedText).select();
    document.execCommand("copy");
    tempInput.remove();
    // toastMagic.success($("#get-copy-to-clipboard").data("success"));
    createToast({
        type: "success",
        heading: "Copied to the clipboard"
    });
});

// ---- selected prodect reset ---
$(".reset-button").on("click", function() {
    $(".select-product-button").text(
        $("#get-select-product-text").data("text")
    );
});

//  ---- Custom dragable scroll ---
function enableDragScroll(selector) {
    const sliders = document.querySelectorAll(selector);

    sliders.forEach(slider => {
        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener("mousedown", e => {
            isDown = true;
            slider.classList.add("active");
            slider.style.cursor = "grabbing";
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener("mouseleave", () => {
            isDown = false;
            slider.classList.remove("active");
            slider.style.cursor = "grab";
        });

        slider.addEventListener("mouseup", () => {
            isDown = false;
            slider.classList.remove("active");
            slider.style.cursor = "grab";
        });

        slider.addEventListener("mousemove", e => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 1;
            slider.scrollLeft = scrollLeft - walk;
        });
    });
}

document.addEventListener("DOMContentLoaded", function() {
    enableDragScroll(".custom-scrollable");
});


// ---- Setup guide scroll animation ----
function handleSetupGuideVisibility() {
    const setupGuideElement = document.querySelector(".setup-guide");

    if (!setupGuideElement) return;

    let lastScrollY = window.pageYOffset + 100;

    setupGuideElement.classList.add("show");

    window.addEventListener("scroll", () => {
        const currentScrollY = window.pageYOffset;

        if (currentScrollY > lastScrollY) {
            setupGuideElement.classList.remove("show");
        } else {
            setupGuideElement.classList.add("show");
        }

    });
}

document.addEventListener("DOMContentLoaded", handleSetupGuideVisibility);

// ---- offcanvas slider
 document.addEventListener("DOMContentLoaded", () => {

     document.querySelectorAll('.collapse').forEach((collapse) => {
         collapse.addEventListener('shown.bs.collapse', function () {
             if (!collapse.classList.contains('swiper-initialized')) {
                 const swiperContainer = collapse.querySelector('.myOffcanvasSwiper');
                 const fractionEl = collapse.querySelector('.swiper-pagination-fraction');
                 const bulletsEl = collapse.querySelector('.swiper-pagination-bullets');
                 const nextBtns = collapse.querySelectorAll('.swiper-button-next-offcanvas, .bullet-next');
                 const prevBtns = collapse.querySelectorAll('.swiper-button-prev-offcanvas, .bullet-prev');

                 if (!swiperContainer) return;

                 const swiper = new Swiper(swiperContainer, {
                     centeredSlides: true,
                     navigation: {
                         nextEl: Array.from(nextBtns),
                         prevEl: Array.from(prevBtns),
                     },
                     pagination: {
                         el: bulletsEl,
                         type: "bullets",
                         clickable: true,
                     },
                     on: {
                         init: function () {
                             updateFraction(this);
                         },
                         slideChange: function () {
                             updateFraction(this);
                         }
                     }
                 });

                 function updateFraction(swiperInstance) {
                     if (fractionEl) {
                         const current = swiperInstance.realIndex + 1;
                         const total = swiperInstance.slides.length;
                         fractionEl.textContent = `${current} / ${total}`;
                     } else {
                         console.warn("Fraction element not found");
                     }
                 }

                 collapse.classList.add('swiper-initialized');
             }
         });
    });

 });

//  ---- tooltip control fr chatting
function isElementVisibleInContainer($el, $container) {
    const el = $el[0];
    const container = $container[0];

    const elRect = el.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    return (
        elRect.top >= containerRect.top &&
        elRect.bottom <= containerRect.bottom
    );
}

function updateTooltipsBasedOnVisibility() {
    const $container = $('#chatting-messages-section');
    $container.find('[data-bs-toggle="tooltip"]').each(function () {
        const $el = $(this);
        const isVisible = isElementVisibleInContainer($el, $container);

        const tooltipInstance = bootstrap.Tooltip.getInstance(this);

        if (isVisible) {
            if (!tooltipInstance) {
                new bootstrap.Tooltip(this);
            }
        } else {
            if (tooltipInstance) {
                tooltipInstance.dispose();
            }
        }
    });
}

$(document).ready(function () {
    updateTooltipsBasedOnVisibility();

    $('#chatting-messages-section').on('scroll', function () {
        updateTooltipsBasedOnVisibility();
    });
});

function hexToRgba(hex, opacity) {
  let r = parseInt(hex.substr(1, 2), 16);
  let g = parseInt(hex.substr(3, 2), 16);
  let b = parseInt(hex.substr(5, 2), 16);
  return `rgba(${r}, ${g}, ${b}, ${opacity})`;
}

$(document).ready(function() {
    $('[data-bg-color]').each(function () {
        let bgColor = $(this).data('bg-color');
        let opacity = 1;

        let classList = $(this).attr('class').split(/\s+/);
        classList.forEach(function (className) {
        if (className.startsWith('bg-opacity-')) {
            let value = parseInt(className.replace('bg-opacity-', '')) || 100;
            opacity = value / 100;
        }
        });

        $(this).css('background-color', hexToRgba(bgColor, opacity));
    });

    $('[data-color]').each(function () {
        let textColor = $(this).data('color');
        $(this).css('color', textColor);
    });
})

// ---- open offcanvas after modal
function openOffcanvasAfterModal(okBtnSelector, modalSelector, offcanvasSelector) {
    let shouldOpen = false;

    $(document).on('click', okBtnSelector, function () {
        shouldOpen = true;
    });

    $(document).on('hidden.bs.modal', modalSelector, function () {
        if (!shouldOpen) return;

        const offcanvasEl = document.querySelector(offcanvasSelector);
        if (!offcanvasEl) return;

        const offcanvas =
            bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);

        offcanvas.show();
        shouldOpen = false;
    });
}

function initActionButton() {

    const $actionWrapper = $(".action-btn-wrapper");
    if (!$actionWrapper.length) return;

    let $actionWrapperContainer = $actionWrapper.closest('.action-btn-wrapper-container');
    const $parent = $actionWrapper.parent();
    let isFixed = false;

    // On the v2 admin/vendor chrome the scrollable element is `.v2-main`
    // (the grid cell), not `window` — `.app-v2` has `height: 100vh` and
    // `overflow: hidden`, so window/document heights never diverge and
    // the sticky-action logic below would never trigger. When v2 is in
    // use, measure against the scroll container instead.
    const v2Main = document.querySelector('.app-v2 > .v2-main');
    const useV2Scroll = !!v2Main;

    function checkContentHeight() {
        const viewportHeight = useV2Scroll ? v2Main.clientHeight    : $(window).height();
        const contentHeight  = useV2Scroll ? v2Main.scrollHeight    : $(document).height();
        const scrollPosition = useV2Scroll ? v2Main.scrollTop       : $(window).scrollTop();

        setTimeout(() => {
            if (contentHeight > viewportHeight) {
                if (!isFixed) {
                    $parent.addClass("fixed-bottom");
                    $actionWrapper.addClass("fixed");
                    $actionWrapperContainer.addClass("fixed");
                    isFixed = true;
                }

                if (scrollPosition + viewportHeight >= contentHeight - 250) {
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
    if (useV2Scroll) {
        $(v2Main).on("scroll", checkContentHeight);
        $(window).on("resize", checkContentHeight);
    } else {
        $(window).on("resize scroll", checkContentHeight);
    }
}
