"use strict";

updateFlashDealProgressBar();
setInterval(updateFlashDealProgressBar, 10000);

$(document).ready(function () {
    var directionFromSession = $("#direction-from-session").data("value");
    directionFromSession = directionFromSession === 'rtl' ? true : false;

    $(".flash-deal-slider").owlCarousel({
        loop: false,
        autoplay: true,
        center: false,
        margin: 10,
        nav: true,
        navText:
            directionFromSession === "rtl"
                ? [
                      "<i class='czi-arrow-right'></i>",
                      "<i class='czi-arrow-left'></i>",
                  ]
                : [
                      "<i class='czi-arrow-left'></i>",
                      "<i class='czi-arrow-right'></i>",
                  ],
        dots: false,
        autoplayHoverPause: true,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        responsive: {
            0: {
                items: 1.1,
            },
            360: {
                items: 1.2,
            },
            375: {
                items: 1.4,
            },
            480: {
                items: 1.8,
            },
            576: {
                items: 2,
            },
            768: {
                items: 3,
            },
            992: {
                items: 4,
            },
            1200: {
                items: 4,
            },
        },
    });

    $(".flash-deal-slider-mobile").owlCarousel({
        loop: true,
        autoplay: true,
        center: true,
        margin: 10,
        nav: true,
        navText:
            directionFromSession === "rtl"
                ? [
                      "<i class='czi-arrow-right'></i>",
                      "<i class='czi-arrow-left'></i>",
                  ]
                : [
                      "<i class='czi-arrow-left'></i>",
                      "<i class='czi-arrow-right'></i>",
                  ],
        dots: false,
        autoplayHoverPause: true,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        responsive: {
            0: {
                items: 1.1,
            },
            360: {
                items: 1.2,
            },
            375: {
                items: 1.4,
            },
            480: {
                items: 1.8,
            },
            576: {
                items: 2,
            },
            768: {
                items: 3,
            },
            992: {
                items: 4,
            },
            1200: {
                items: 4,
            },
        },
    });

    //featured_products_list old
    // let featuredProductsLoopEnable = $('#featured_products_list').data('loop')?.toString() === 'true';
    // $("#featured_products_list").owlCarousel({
    //     loop: featuredProductsLoopEnable,
    //     autoplay: true,
    //     margin: 20,
    //     nav: true,
    //     navText:
    //         directionFromSession === "rtl"
    //             ? [
    //                   "<i class='czi-arrow-right'></i>",
    //                   "<i class='czi-arrow-left'></i>",
    //               ]
    //             : [
    //                   "<i class='czi-arrow-left'></i>",
    //                   "<i class='czi-arrow-right'></i>",
    //               ],
    //     dots: false,
    //     autoplayHoverPause: true,
    //     rtl: directionFromSession === "rtl",
    //     ltr: directionFromSession === "ltr",
    //     responsive: {
    //         0: {
    //             items: 1,
    //         },
    //         360: {
    //             items: 1,
    //         },
    //         375: {
    //             items: 1,
    //         },
    //         540: {
    //             items: 2,
    //         },
    //         576: {
    //             items: 2,
    //         },
    //         768: {
    //             items: 3,
    //         },
    //         992: {
    //             items: 4,
    //         },
    //         1200: {
    //             items: 6,
    //         },
    //     },
    // });

    //featured_products_list New
    $(".featured_products_listSlide").each(function(){
        let maxItems = $(this).data('slide-items');
        $(this).owlCarousel({
            autoplay: true,
            margin: 20,
            nav: true,
            navText:
            directionFromSession === "rtl"
                ? [
                      "<i class='czi-arrow-right'></i>",
                      "<i class='czi-arrow-left'></i>",
                  ]
                : [
                      "<i class='czi-arrow-left'></i>",
                      "<i class='czi-arrow-right'></i>",
                  ],
        dots: false,
        autoplayHoverPause: true,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",

            responsive: {
                0: {
                    items: 1,
                    loop: maxItems > 1
                },
                360: {
                    items: 1.02,
                    loop: maxItems > 1.02
                },
                375: {
                    items: 1.02,
                    loop: maxItems > 1.02
                },
                540: {
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
                    items: 6,
                    loop: maxItems > 6
                },
            },
        });
    })

     //auction_products_list New
    $(".auction_products_listSlide").each(function () {

        let $slider = $(this);

        let totalItems = Number($slider.data("slide-items"));

        $slider.owlCarousel({

            autoplay: totalItems > 1,
            autoplayTimeout: 3000,
            autoplaySpeed: 1000,
            autoplayHoverPause: true,

            smartSpeed: 800,
            navSpeed: 800,

            margin: 20,

            nav: true,
            dots: false,

            rtl: directionFromSession === "rtl",

            slideBy: 1,

            mouseDrag: true,
            touchDrag: true,
            pullDrag: true,

            stagePadding: 0,
            rewind: false,

            navText:
                directionFromSession === "rtl"
                    ? [
                        "<i class='czi-arrow-right'></i>",
                        "<i class='czi-arrow-left'></i>",
                    ]
                    : [
                        "<i class='czi-arrow-left'></i>",
                        "<i class='czi-arrow-right'></i>",
                    ],

            responsive: {

                0: {
                    margin: 10,
                    items: 1.2,
                    loop: totalItems > 1
                },

                360: {
                    margin: 10,
                    items: 1.2,
                    loop: totalItems > 1
                },

                375: {
                    margin: 10,
                    items: 1.2,
                    loop: totalItems > 1
                },

                540: {
                    margin: 10,
                    items: totalItems <= 2 ? 1.2 : 2,
                    loop: totalItems > 2
                },

                576: {
                    items: totalItems <= 2 ? 1.5 : 2,
                    loop: totalItems > 2
                },

                768: {
                    items: totalItems <= 3 ? 2 : 3,
                    loop: totalItems > 3
                },

                992: {
                    items: totalItems <= 4 ? 3 : 4,
                    loop: totalItems > 4
                },

                1200: {
                    items: totalItems <= 6 ? 5 : 5.1,
                    loop: totalItems > 6
                }
            },

            onInitialized: updateNav,
            onTranslated: updateNav,
            onResized: updateNav
        });

        function updateNav(event) {

            let owl = event.relatedTarget;

            if (!owl) return;

            let total = owl.items().length;
            let visible = owl.settings.items;

            if (total <= Math.ceil(visible)) {
                $slider.find(".owl-nav").hide();
            } else {
                $slider.find(".owl-nav").show();
            }
        }
    });


    //New Arrivals Product New
    $(".new-arrivals-product").each(function(){
        let maxItems = $(this).data('slide-items');
        $(this).owlCarousel({
            autoplay: true,
            margin: 20,
            nav: true,
            navText:
                directionFromSession === "rtl"
                    ? [
                        "<i class='czi-arrow-right'></i>",
                        "<i class='czi-arrow-left'></i>",
                    ]
                    : [
                        "<i class='czi-arrow-left'></i>",
                        "<i class='czi-arrow-right'></i>",
                    ],
            dots: false,
            autoplayHoverPause: true,
            rtl: directionFromSession === "rtl",

            responsive: {
                0: {
                    items: 1,
                    loop: maxItems > 1
                },
                360: {
                    items: 1.02,
                    loop: maxItems > 1.02
                },
                375: {
                    items: 1.02,
                    loop: maxItems > 1.02
                },
                540: {
                    items: 2,
                    loop: maxItems > 2
                },
                576: {
                    items: 2,
                    loop: maxItems > 2
                },
                768: {
                    items: 2,
                    loop: maxItems > 2
                },
                992: {
                    items: 2,
                    loop: maxItems > 2
                },
                1200: {
                    items: 4,
                    loop: maxItems > 4
                },
                1400: {
                    items: 4,
                    loop: maxItems > 4
                },
            },
        });
    })
    //New Arrivals Product old
    // $(".new-arrivals-product").owlCarousel({
    //     loop: true,
    //     autoplay: true,
    //     margin: 20,
    //     nav: true,
    //     navText:
    //         directionFromSession === "rtl"
    //             ? [
    //                   "<i class='czi-arrow-right'></i>",
    //                   "<i class='czi-arrow-left'></i>",
    //               ]
    //             : [
    //                   "<i class='czi-arrow-left'></i>",
    //                   "<i class='czi-arrow-right'></i>",
    //               ],
    //     dots: false,
    //     autoplayHoverPause: true,
    //     rtl: directionFromSession === "rtl",
    //     ltr: directionFromSession === "ltr",
    //     responsive: {
    //         0: {
    //             items: 1,
    //         },
    //         360: {
    //             items: 1.02,
    //         },
    //         375: {
    //             items: 1.02,
    //         },
    //         540: {
    //             items: 2,
    //         },
    //         576: {
    //             items: 2,
    //         },
    //         768: {
    //             items: 2,
    //         },
    //         992: {
    //             items: 2,
    //         },
    //         1200: {
    //             items: 4,
    //         },
    //         1400: {
    //             items: 4,
    //         },
    //     },
    // });





    //category-wise-product-slider Old
    // $(".category-wise-product-slider").each(function () {
    //     let loopEnable = $(this).data('loop')?.toString() === 'true';

    //     $(this).owlCarousel({
    //         loop: loopEnable,
    //         autoplay: true,
    //         margin: 20,
    //         nav: true,
    //         navText:
    //             directionFromSession === "rtl"
    //                 ? [
    //                       "<i class='czi-arrow-right'></i>",
    //                       "<i class='czi-arrow-left'></i>",
    //                   ]
    //                 : [
    //                       "<i class='czi-arrow-left'></i>",
    //                       "<i class='czi-arrow-right'></i>",
    //                   ],
    //         dots: false,
    //         autoplayHoverPause: true,
    //         rtl: directionFromSession === "rtl",
    //         responsive: {
    //             0: {
    //                 items: 1.2,
    //             },
    //             375: {
    //                 items: 1.4,
    //             },
    //             425: {
    //                 items: 2,
    //             },
    //             576: {
    //                 items: 3,
    //             },
    //             768: {
    //                 items: 4,
    //             },
    //             992: {
    //                 items: 5,
    //             },
    //             1200: {
    //                 items: 6,
    //             },
    //         },
    //         onInitialized: checkNavigationButtons,
    //     });
    // });

    //category-wise-product-slider New
    $(".category-wise-product-slider").each(function(){
        let maxItems = $(this).data('slide-items');
        $(this).owlCarousel({
            autoplay: true,
            margin: 20,
            nav: true,
            navText:
                directionFromSession === "rtl"
                    ? [
                          "<i class='czi-arrow-right'></i>",
                          "<i class='czi-arrow-left'></i>",
                      ]
                    : [
                          "<i class='czi-arrow-left'></i>",
                          "<i class='czi-arrow-right'></i>",
                      ],
            dots: false,
            autoplayHoverPause: true,
            rtl: directionFromSession === "rtl",
            responsive: {
                0: {
                    items: 1.2,
                    loop: maxItems > 1.2
                },
                375: {
                    items: 1.4,
                    loop: maxItems > 1.4
                },
                425: {
                    items: 2,
                    loop: maxItems > 2
                },
                576: {
                    items: 3,
                    loop: maxItems > 3
                },
                768: {
                    items: 4,
                    loop: maxItems > 4
                },
                992: {
                    items: 5,
                    loop: maxItems > 5
                },
                1200: {
                    items: 6,
                    loop: maxItems > 6
                },
            },
            onInitialized: checkNavigationButtons,
        });
    })

    function checkNavigationButtons(event) {
        var itemCount = event.item.count;
        let owlNav = $(".owl-nav");
        itemCount > 1 ? owlNav.show() : owlNav.hide();
    }

    let isLoopHeroSlider = $(".hero-slider").data('loop')?.toString() === '1';

    $(".hero-slider").owlCarousel({
        loop: isLoopHeroSlider,
        autoplay: isLoopHeroSlider,
        margin: 20,
        nav: isLoopHeroSlider,
        navText:
            directionFromSession === "rtl"
                ? [
                      "<i class='czi-arrow-right'></i>",
                      "<i class='czi-arrow-left'></i>",
                  ]
                : [
                      "<i class='czi-arrow-left'></i>",
                      "<i class='czi-arrow-right'></i>",
                  ],
        dots: isLoopHeroSlider,
        autoplayHoverPause: isLoopHeroSlider,
        autoplaySpeed: 1500,
        slideTransition: "linear",
        items: 1,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
    });

    //Brands SLider Old
    // $(".brands-slider").owlCarousel({
    //     loop: false,
    //     autoplay: true,
    //     margin: 10,
    //     nav: true,
    //     navText:
    //         directionFromSession === "rtl"
    //             ? [
    //                   "<i class='czi-arrow-right'></i>",
    //                   "<i class='czi-arrow-left'></i>",
    //               ]
    //             : [
    //                   "<i class='czi-arrow-left'></i>",
    //                   "<i class='czi-arrow-right'></i>",
    //               ],
    //     dots: false,
    //     rtl: directionFromSession === "rtl",
    //     ltr: directionFromSession === "ltr",
    //     autoplayHoverPause: true,
    //     responsive: {
    //         0: {
    //             items: 4,
    //         },
    //         360: {
    //             items: 5,
    //         },
    //         576: {
    //             items: 6,
    //         },
    //         768: {
    //             items: 7,
    //         },
    //         992: {
    //             items: 9,
    //         },
    //         1200: {
    //             items: 11,
    //         },
    //         1400: {
    //             items: 12,
    //         },
    //     },
    // });

    //New Arrivals Product New
    $(".brands-slider").each(function(){
        let maxItems = $(this).data('slide-items');
        $(this).owlCarousel({
            autoplay: true,
            margin: 20,
            nav: true,
            navText:
            directionFromSession === "rtl"
                ? [
                      "<i class='czi-arrow-right'></i>",
                      "<i class='czi-arrow-left'></i>",
                  ]
                : [
                      "<i class='czi-arrow-left'></i>",
                      "<i class='czi-arrow-right'></i>",
                  ],
            dots: false,
            rtl: directionFromSession === "rtl",
            ltr: directionFromSession === "ltr",
            autoplayHoverPause: true,
            
            responsive: {
                0: {
                    items: 4,
                    loop: maxItems > 4
                },
                360: {
                    items: 5,
                    loop: maxItems > 5
                },
                576: {
                    items: 6,
                    loop: maxItems > 6
                },
                768: {
                    items: 7,
                    loop: maxItems > 7
                },
                992: {
                    items: 9,
                    loop: maxItems > 9
                },
                1200: {
                    items: 11,
                    loop: maxItems > 11
                },
                1400: {
                    items: 12,
                    loop: maxItems > 12
                },
            },            
        });
    })

    $(".footer-banner-slider").owlCarousel({
        loop: true,
        autoplay: true,
        margin: 10,
        nav: false,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        autoplayHoverPause: true,
        items: 1,
    });

    $("#category-slider, #top-seller-slider").owlCarousel({
        loop: false,
        autoplay: true,
        margin: 20,
        nav: false,
        dots: true,
        autoplayHoverPause: true,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        responsive: {
            0: {
                items: 2,
            },
            360: {
                items: 3,
            },
            375: {
                items: 3,
            },
            540: {
                items: 4,
            },
            576: {
                items: 5,
            },
            768: {
                items: 6,
            },
            992: {
                items: 8,
            },
            1200: {
                items: 10,
            },
            1400: {
                items: 11,
            },
        },
    });

    $(".categories--slider").owlCarousel({
        loop: false,
        autoplay: true,
        margin: 20,
        nav: false,
        dots: false,
        autoplayHoverPause: true,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        responsive: {
            0: {
                items: 3,
            },
            // 360: {
            //     items: 3.2,
            // },
            // 375: {
            //     items: 3.5,
            // },
            540: {
                items: 4,
            },
            576: {
                items: 5,
            },
            768: {
                items: 6,
            },
            992: {
                items: 8,
            },
            1200: {
                items: 10,
            },
            1400: {
                items: 11,
            },
        },
    });

    const othersStore = $(".others-store-slider").owlCarousel({
        responsiveClass: true,
        nav: false,
        dots: false,
        loop: true,
        autoplay: true,
        autoplayTimeout: 5000,
        autoplayHoverPause: true,
        smartSpeed: 600,
        rtl: directionFromSession === "rtl",
        ltr: directionFromSession === "ltr",
        responsive: {
            0: {
                items: 1.3,
                margin: 10,
            },
            480: {
                items: 2,
                margin: 26,
            },
            768: {
                items: 2,
                margin: 26,
            },
            992: {
                items: 3,
                margin: 26,
            },
            1200: {
                items: 4,
                margin: 26,
            },
        },
    });

    $(".store-next").on("click", function () {
        othersStore.trigger("next.owl.carousel", [600]);
    });

    $(".store-prev").on("click", function () {
        othersStore.trigger("prev.owl.carousel", [600]);
    });
});

// Banner grid slider: the banners an admin marked `slider` share one rotating
// full-width slot (home promo grid and category section banners alike).
$(function () {
    const directionFromSession = $("html").attr("dir") === "rtl" ? "rtl" : "ltr";
    $(".banner-grid-slider").each(function () {
        $(this).owlCarousel({
            items: 1,
            loop: $(this).children().length > 1,
            autoplay: true,
            autoplayTimeout: 6000,
            autoplayHoverPause: true,
            margin: 0,
            nav: true,
            navText:
                directionFromSession === "rtl"
                    ? ["<i class='czi-arrow-right'></i>", "<i class='czi-arrow-left'></i>"]
                    : ["<i class='czi-arrow-left'></i>", "<i class='czi-arrow-right'></i>"],
            dots: true,
            rtl: directionFromSession === "rtl",
        });
    });
});
