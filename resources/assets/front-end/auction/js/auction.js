"use strict";

function stickyNavbar () {
    let t = document.querySelector(".navbar-sticky");
    if (null != t) {
        let e = t.classList,
            r = t.offsetHeight;
        e.contains("navbar-floating") && e.contains("navbar-dark")
            ? window.addEventListener("scroll", function (e) {
                    500 < e.currentTarget.pageYOffset
                        ? (t.classList.remove("navbar-dark"),
                        t.classList.add("navbar-light", "navbar-stuck"))
                        : (t.classList.remove(
                            "navbar-light",
                            "navbar-stuck"
                        ),
                        t.classList.add("navbar-dark"));
                })
            : e.contains("navbar-floating") &&
                e.contains("navbar-light")
            ? window.addEventListener("scroll", function (e) {
                    500 < e.currentTarget.pageYOffset
                        ? t.classList.add("navbar-stuck")
                        : t.classList.remove("navbar-stuck");
                })
            : window.addEventListener("scroll", function (e) {
                    const isDesktop = window.matchMedia("(min-width: 1199px)").matches;
                    200 < e.currentTarget.pageYOffset
                        ? (isDesktop && (document.body.style.paddingTop = r + "px"),
                        t.classList.add("navbar-stuck"))
                        : ((document.body.style.paddingTop = ""),
                        t.classList.remove("navbar-stuck"));
                });
    }
}
function stuckNavbarMenuToggle () {
    let e = document.querySelector(".navbar-stuck-toggler"),
        t = document.querySelector(".navbar-stuck-menu");
    e.addEventListener("click", function (e) {
        t.classList.toggle("show"), e.preventDefault();
        this.classList.toggle("show");
    });
}

window.addEventListener("load", function () {
    stickyNavbar();
    stuckNavbarMenuToggle();

    $(window).on("resize", orderSummaryStickyFunction);
    $(window).on("scroll", orderSummaryStickyFunction);
});

function orderSummaryStickyFunction() {
    try {
        const stickyElement = $(".bottom-sticky3");
        const offsetElement = $(".__cart-total_sticky .proceed_to_next_button");

        const elementOffset = offsetElement.offset().top;
        const scrollTop = $(window).scrollTop();
        if (scrollTop >= elementOffset - $(window).height() + 50) {
            stickyElement?.addClass("stick");
            $(".floating-btn-grp")?.removeClass("style-2");
        } else {
            stickyElement?.removeClass("stick");
            $(".floating-btn-grp")?.addClass("style-2");
        }
    } catch (e) { }

    let btnScrollTop = document.querySelector(".btn-scroll-top");
    if (null != btnScrollTop) {
        let basedScrollPosition = parseInt(600, 10);
        window.addEventListener("scroll", function (e) {
            e.currentTarget.pageYOffset > basedScrollPosition
                ? btnScrollTop.classList.add("show")
                : btnScrollTop.classList.remove("show");
        });
    }
}


 /* Toggle Menu */
$(".menu-btn").on("click", function () {
    $(".aside").toggleClass("active");
    $(".filter-toggle-aside").removeClass("active");

    if ($(this).hasClass("search")) {
        setTimeout(function () {
            $(".aside .search-bar-input-mobile").focus();
        }, 100);
    }
});
$(".aside-close > i").on("click", function () {
    $(".aside").removeClass("active");
});
$(window).on("resize", function () {
    if ($(window).width() > 1199) {
        $(".aside").removeClass("active");
    }
});


/* Parent li add class */
$(".header .nav-wrapper, .aside .main-nav, .common-nav")
    .find(".sub-menu, .sub_menu")
    .parents("ul li")
    .addClass("has-sub-item");

/* Submenu Opened */
$(".aside .aside-body, .common-nav")
    .find(".has-sub-item > a, .has-sub-item > label")
    .on("click", function (event) {
        event.preventDefault();
        $(this)
            .parent(".has-sub-item")
            .siblings("li")
            .removeClass("sub-menu-opened");
        $(this).parent(".has-sub-item").toggleClass("sub-menu-opened");
        if ($(this).siblings("ul").hasClass("open")) {
            $(this).siblings("ul").removeClass("open").slideUp("200");
        } else {
            $(this)
                .parent("li")
                .siblings("li")
                .find("ul")
                .removeClass("open")
                .slideUp("200");
            $(this).siblings("ul").addClass("open").slideDown("200");
        }
    });



//Product Details Content Collapse
$(document).ready(function () {

        function applyShowMoreLogic() {
        $(".tab-pane.active").each(function () {
            let content = $(this).find(".details-content-wrap");
            let button  = $(this).find(".see-more-details");

            if (!content.length || !button.length) return;

            if (content[0].scrollHeight > 280) {
                content.addClass(" active");
                button.show();
            } else {
                content.removeClass(" active");
                button.hide();
            }
        });
    }

    applyShowMoreLogic();

    $(document).on("shown.bs.tab", '[data-bs-toggle="pill"], [data-bs-toggle="tab"]', function () {
        applyShowMoreLogic();
    });

    $(document).on("click", ".see-more-details", function () {

        let tabPane = $(this).closest(".tab-pane");
        let description = tabPane.find(".p-details-description");

        description.toggleClass("overflow-y-auto");

        if (description.hasClass("overflow-y-auto")) {

            $(this).text($("#all-msg-container").data("seemore"));

        } else {

            $(this).text($("#all-msg-container").data("afterextend"));
        }
    });

});


//Day Countdown
(function () {
    const flashContainers = document.querySelectorAll('.flash-deal-countdown');
    if (!flashContainers.length) return;
    flashContainers.forEach(function (flashContainer) {

        const targetDate = new Date(flashContainer.getAttribute('data-countdown')).getTime();
        function updateTimer() {

            const now = new Date().getTime();
            const diff = targetDate - now;

            if (diff < 0) {
                return;
            }
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            flashContainer.querySelector('.cz-countdown-days .cz-countdown-value').innerText = days.toString().padStart(2, '0');
            flashContainer.querySelector('.cz-countdown-hours .cz-countdown-value').innerText = hours.toString().padStart(2, '0');
            flashContainer.querySelector('.cz-countdown-minutes .cz-countdown-value').innerText = minutes.toString().padStart(2, '0');
            flashContainer.querySelector('.cz-countdown-seconds .cz-countdown-value').innerText = seconds.toString().padStart(2, '0');
        }
        updateTimer();
        setInterval(updateTimer, 1000);

    });
})();

//IntelIcon Initialize
(function() {
    const telInput = document.querySelector('input[type="tel"]');
    if (telInput) {
        window.intlTelInput(telInput, {
            initialCountry: "bd",
            preferredCountries: ["bd", "us", "gb"],
            autoPlaceholder: "polite",
        });
    }
})();



// ==================================================================
// Auction page sliders — standardized navigation
// No loop, no circular/wrap-around. Left arrow disabled at first item,
// right arrow disabled at last item. Same behavior for every section.
// ==================================================================
$(document).ready(function () {

    let directionFromSession = $('html').attr('dir') || "ltr";

    let navText = directionFromSession === "rtl"
        ? [
            "<i class='fi fi-rr-angle-right fs-14'></i>",
            "<i class='fi fi-rr-angle-left fs-14'></i>",
        ]
        : [
            "<i class='fi fi-rr-angle-left fs-14'></i>",
            "<i class='fi fi-rr-angle-right fs-14'></i>",
        ];

    // Disable arrows at the boundaries; hide nav when nothing to scroll.
    function updateAuctionNav(event) {

        let owl = event.relatedTarget;
        if (!owl) return;

        let $slider = $(owl.$element);
        let current = owl.current();
        let items = owl.items().length;
        let visible = owl.settings.items;

        $slider.find(".owl-prev").toggleClass("disabled", current <= 0);
        $slider.find(".owl-next").toggleClass("disabled", current >= items - Math.ceil(visible));

        if (items <= visible) {
            $slider.find(".owl-nav").hide();
        } else {
            $slider.find(".owl-nav").show();
        }
    }

    // Shared initializer — same behavior, per-section responsive layout only.
    function initAuctionSlider(selector, options) {

        options = options || {};

        $(selector).each(function () {

            let $slider = $(this);
            let totalItems = Number($slider.data("slide-items"));

            $slider.owlCarousel({
                margin: options.margin || 20,
                rtl: directionFromSession === "rtl",
                autoplay: false,
                autoplayHoverPause: true,
                smartSpeed: 800,
                navSpeed: 800,
                nav: true,
                dots: false,
                slideBy: 1,
                mouseDrag: true,
                touchDrag: true,
                pullDrag: true,
                loop: false,
                rewind: false,
                stagePadding: 0,
                navText: navText,
                responsive: options.responsive(totalItems),
                onInitialized: updateAuctionNav,
                onTranslated: updateAuctionNav,
                onResized: updateAuctionNav,
            });
        });
    }

    //Ending Soon
    initAuctionSlider(".ending_soon_slidewrap", {
        responsive: function () {
            return {
                0: { items: 1, margin: 12 },
                360: { items: 1.02, margin: 12 },
                425: { items: 2, margin: 12 },
                540: { items: 2, margin: 12 },
                576: { items: 2 },
                768: { items: 3 },
                992: { items: 4 },
                1200: { items: 5 },
            };
        }
    });

    //Categories
    initAuctionSlider(".categories-product-slidewrap", {
        responsive: function () {
            return {
                0: { items: 1 },
                360: { items: 1.02 },
                480: { items: 2 },
                540: { items: 2 },
                576: { items: 2 },
                768: { items: 3 },
                992: { items: 4 },
                1200: { items: 4 },
            };
        }
    });

    //Recently Viewed
    initAuctionSlider(".recently_views_slidewrap", {
        responsive: function () {
            return {
                0: { items: 1 },
                360: { items: 1.02 },
                480: { items: 1 },
                560: { items: 2 },
                576: { items: 2 },
                768: { items: 2 },
                992: { items: 3 },
                1200: { items: 3 },
            };
        }
    });

    //Upcoming Auction
    initAuctionSlider(".upcoming_auction_slidewrap", {
        responsive: function (totalItems) {
            return {
                0: { items: 1, margin: 12 },
                425: { items: totalItems <= 2 ? 1.3 : 2, margin: 12 },
                576: { items: totalItems <= 2 ? 1.3 : 1.6 },
                768: { items: totalItems <= 3 ? 2 : 2.3 },
                992: { items: totalItems <= 4 ? 3 : 3.2 },
                1200: { items: totalItems <= 5 ? 4 : 4 },
            };
        }
    });

    //Category-wise Auction Products
    initAuctionSlider(".including_product_slidewrap", {
        margin: 10,
        responsive: function (totalItems) {
            return {
                0: { items: 1 },
                576: { items: totalItems <= 1 ? 1 : 1.2 },
                768: { items: totalItems < 2 ? 1 : 2 },
                992: { items: totalItems <= 3 ? 2 : 2.4 },
                1200: { items: totalItems <= 3 ? 2 : 3 },
            };
        }
    });
});

//footer-top-slider
$(document).ready(function () {
    let directionFromSession = $('html').attr('dir') || "ltr";
    $(".footer-top-slider").each(function(){
        let maxItems = $(this).data('slide-items');
        $(this).owlCarousel({
            autoplay: false,
            margin: 20,
            nav: false,
            responsive: {
                0: {
                    margin: 12,
                    items: 2,
                    loop: maxItems > 2
                },
                360: {
                    margin: 12,
                    items: 2,
                    loop: maxItems > 2
                },
                480: {
                    margin: 12,
                    items: 2,
                    loop: maxItems > 2
                },
                560: {
                    items: 2,
                    loop: maxItems > 2
                },
                576: {
                    items: 2,
                    loop: maxItems > 2
                },
                768: {
                    items: 3,
                    loop: maxItems > 3
                },
                992: {
                    items: 4,
                    loop: maxItems > 4
                },
                1200: {
                    items: 4,
                    loop: maxItems > 4
                },
            },
        });
    })
});

//Product Details slider
$(document).ready(function () {
    const isRTL = $("html").attr("dir") === "rtl";
    $(".product-slider").each(function () {
        const $wrapper = $(this);
        const $big = $wrapper.find(".big-slider");
        const $thumb = $wrapper.find(".thumb-slider");
        if (!$big.length || !$thumb.length) return;

        // ==BIG SLIDER==
        $big.owlCarousel({
            items: 1,
            margin: 10,
            loop: false,
            dots: false,
            nav: false,
            rtl: isRTL,
            smartSpeed: 500
        });
        // ==THUMB SLIDER==
        $thumb.owlCarousel({
            items: 4,
            margin: 8,
            loop: false,
            dots: false,
            nav: false,
            rtl: isRTL,
            smartSpeed: 400
        });
        setTimeout(function () {
            const currentIndex = $big.find(".owl-item.active").index();
            setActiveThumb(currentIndex);
        }, 50);

        // ==BIG CHANGE EVENT==
        $big.on("changed.owl.carousel", function (event) {
            const index = event.item.index;
            setActiveThumb(index);
        });
        // ==THUMB CLICK==
        $thumb.on("click", ".owl-item", function () {
            const index = $(this).index();
            $big.trigger("to.owl.carousel", [index, 400, true]);
            setActiveThumb(index);
        });
        // ==THUMB NAV BUTTONS==
        $wrapper.find(".thumb-prev").on("click", function () {
            $thumb.trigger("prev.owl.carousel");
        });
        $wrapper.find(".thumb-next").on("click", function () {
            $thumb.trigger("next.owl.carousel");
        });
        // ==ACTIVE CONTROLLER==
        function setActiveThumb(index) {
            $thumb.find(".owl-item")
                .removeClass("current")
                .eq(index)
                .addClass("current");
            const visibleStart = $thumb.find(".owl-item.active").first().index();
            const visibleEnd = $thumb.find(".owl-item.active").last().index();
            if (index > visibleEnd) {
                $thumb.trigger("to.owl.carousel", [index, 300, true]);
            }

            if (index < visibleStart) {
                $thumb.trigger("to.owl.carousel", [index, 300, true]);
            }
        }
    });
    // $(".product-slider").each(function () {
    //     const $wrapper = $(this);
    //     const $big = $wrapper.find(".big-slider");
    //     // INIT EASYZOOM FIRST SLIDE
    //     if ($.fn.easyZoom) {
    //         $big.find(".owl-item.active .easyzoom").easyZoom();
    //     }
    //     // RE-INIT ON SLIDE CHANGE
    //     $big.on("changed.owl.carousel", function () {
    //         // Destroy all zoom instances
    //         $wrapper.find(".easyzoom").each(function () {
    //             const api = $(this).data("easyZoom");
    //             if (api) api.teardown();
    //         });
    //         // Init only active slide
    //         const $current = $big.find(".owl-item.active .easyzoom");

    //         if ($current.length) {
    //             $current.easyZoom();
    //         }
    //     });
    // });

    //Product Details Swiper Slider
    function initSliderWithZoom() {
        $(".easyzoom").each(function () {
            $(this).easyZoom();
        });

        new Swiper(".quickviewSlider2", {
            slidesPerView: 1,
            spaceBetween: 10,
            loop: false,
            thumbs: {
                swiper: new Swiper(".quickviewSliderThumb2", {
                    spaceBetween: 10,
                    slidesPerView: 'auto',
                    watchSlidesProgress: true,
                    navigation: {
                        nextEl: ".swiper-quickview-button-next",
                        prevEl: ".swiper-quickview-button-prev",
                    },
                }),
            },
        });
    }

    initSliderWithZoom();

});

//img to convert svg
$("img.svg").each(function () {
    let $img = jQuery(this);
    let imgID = $img.attr("id");
    let imgClass = $img.attr("class");
    let imgURL = $img.attr("src");

    jQuery.get(
        imgURL,
        function (data) {
            let $svg = jQuery(data).find("svg");
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

//Categories accordion menu
$(".menu--caret").on("click", function (e) {
    var element = $(this).closest(".menu--caret-accordion");
    if (element.hasClass("open")) {
        element.removeClass("open");
        element.find(".menu--caret-accordion").removeClass("open");
        element.find(".card-body").slideUp(300, "swing");
    } else {
        element.addClass("open");
        element.children(".card-body").slideDown(300, "swing");
        element
            .siblings(".menu--caret-accordion")
            .children(".card-body")
            .slideUp(300, "swing");
        element.siblings(".menu--caret-accordion").removeClass("open");
        element
            .siblings(".menu--caret-accordion")
            .find(".menu--caret-accordion")
            .removeClass("open");
        element
            .siblings(".menu--caret-accordion")
            .find(".card-body")
            .slideUp(300, "swing");
    }
});


//Tooltip Initialize
$(function () {
  $('[data-bs-toggle="tooltip"]').tooltip()
})

//select2 Initialize
$(document).ready(function () {
    $('.select2').select2();
});

//Daterangepicker Initialize
$(document).on('focus', '.js-select2-date', function () {
    if (!$(this).data('daterangepicker')) {
        $(this).daterangepicker({
            parentEl: 'body',
            singleDatePicker: true,
            timePicker: false,
            opens: 'left',
            drops: 'down',
            locale: {
                format: 'DD MMM YYYY, hh:mm A'
            }
        });
    }
});

$(document).ready(function () {
    $(".image-uploader__zip").on("change", function (event) {
        const file = event.target.files[0];
        const wrapper = $(this).closest(".image-uploader");
        const target = wrapper.find(".image-uploader__title");
        const removeBtn = wrapper.find(".zip-remove-btn");
        const icon = wrapper.find(".upload-preview-icon");

        const allowedTypes = ["application/pdf", "audio/mpeg", "video/mp4", "video/webm"];

        if (file) {

            if (!allowedTypes.includes(file.type)) {
                toastMagic.error("Only PDF, MP3, MP4 and WEBM files are allowed");
                $(this).val(null);

                target.text("Upload File").addClass("text-info");
                icon.attr("src", wrapper.data("default-icon"));
                removeBtn.hide();
                return;
            }

            target.text(file.name).removeClass("text-info");

            let newIcon = wrapper.data("default-icon");

            if (file.type === "application/pdf") {
                newIcon = wrapper.data("pdf-icon");
            }
            if (file.type === "audio/mpeg") {
                newIcon = wrapper.data("mp3-icon");
            }
            if (file.type === "video/mp4") {
                newIcon = wrapper.data("mp4-icon");
            }
            if (file.type === "video/webm") {
                newIcon = wrapper.data("mp4-icon");
            }

            icon.attr("src", newIcon);

            removeBtn.show();
        } else {
            target.text("Upload File").addClass("text-info");
            icon.attr("src", wrapper.data("default-icon"));
            removeBtn.hide();
        }
    });

    $(".image-uploader .zip-remove-btn").on("click", function () {
        const wrapper = $(this).closest(".image-uploader");

        wrapper.find(".image-uploader__zip").val(null);
        wrapper.find(".image-uploader__title")
            .text("Upload File")
            .addClass("text-info");

        wrapper.find(".upload-preview-icon")
            .attr("src", wrapper.data("default-icon"));

        $(this).hide();
    });

    function toggleVideoType() {
        let selected = $('input[name="video_provider"]:checked').val();

        if (selected === 'youtube_link') {
            $('.youtube-link-here').show();
            $('.upload-video-file-here').hide();
        } else if (selected === 'custom_video') {
            $('.youtube-link-here').hide();
            $('.upload-video-file-here').show();
        }
    }

    toggleVideoType();

    $(document).on('change', 'input[name="video_provider"]', function () {
        toggleVideoType();
    });

    $(document).on('reset', 'form', function () {
        let form = $(this);

        setTimeout(function () {
            toggleVideoType();
        }, 0);
    });

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

$(".change-language").on("click", function () {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        type: "POST",
        url: $(this).data("action"),
        data: {
            language_code: $(this).data("language-code"),
        },
        success: function (data) {
            toastr.success(data.message);
            location.reload();
        },
    });
});
