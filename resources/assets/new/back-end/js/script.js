(function ($) {
    "use strict";

    // Bootstrap Tooltip Init.
    const tooltipTriggerList = document.querySelectorAll(
        '[data-bs-toggle="tooltip"]'
    );
    const tooltipList = [...tooltipTriggerList].map(
        tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
            trigger: "hover focus"
        })
    );

    // Hide any open tooltips on scroll so they don't float with the icon.
    const shownTooltips = new Set();
    document.addEventListener("shown.bs.tooltip", e => shownTooltips.add(e.target));
    document.addEventListener("hidden.bs.tooltip", e => shownTooltips.delete(e.target));

    const hideShownTooltips = () => {
        if (!shownTooltips.size) return;
        [...shownTooltips].forEach(el => {
            const instance = bootstrap.Tooltip.getInstance(el);
            if (instance) instance.hide();
        });
    };
    window.addEventListener("scroll", hideShownTooltips, true);
    window.addEventListener("touchmove", hideShownTooltips, { passive: true });

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


    // Document Ready
    $(document).ready(function () {
        // --- sidebar search ---
        function resetSidebar($navItems) {
            $navItems.each(function () {
                const $el = $(this);
                const $submenu = $el.find(".aside-submenu");

                if ($submenu.length) {
                    $submenu.hide().removeClass("open");
                    $el.removeClass("sub-menu-opened").show();
                    $submenu.find("li").show();
                } else {
                    $el.show();
                }

                const $prevTitle = $el.prevAll(".nav-item_title").first();
                if ($prevTitle.length) $prevTitle.show();
            });
        }

        $(document).on("keyup", ".search-aside-attribute", function () {
            const sidebarId = "offcanvasAside";

            $('.offcanvas.show').each(function () {
                if ($(this).attr('id') !== sidebarId) {
                    const oc = bootstrap.Offcanvas.getInstance(this);
                    if (oc) oc.hide();
                }
            });

            const value = $(this).val().toLowerCase().trim();
            const $container = $(this).closest(".search-aside-attribute-container");
            const $navItems = $container.find("ul.aside-nav > li");

            if (!value) {
                resetSidebar($navItems);
                return;
            }

            $navItems.each(function () {
                const $el = $(this);
                const text = $el.text().toLowerCase().trim();
                const $submenu = $el.find(".aside-submenu");

                if ($el.hasClass("nav-item_title")) {
                    $el.hide();
                    return;
                }

                let isVisible = false;

                if ($submenu.length) {
                    const $subItems = $submenu.find("li");
                    const hasSubMatch = $subItems.toArray().some(sub =>
                        $(sub).text().toLowerCase().trim().includes(value)
                    );

                    if (text.includes(value) || hasSubMatch) {
                        $subItems.show();
                        $submenu.show().addClass("open");
                        $el.show().addClass("sub-menu-opened");
                        isVisible = true;
                    } else {
                        $subItems.hide();
                        $submenu.hide().removeClass("open");
                        $el.hide().removeClass("sub-menu-opened");
                    }
                } else {
                    const isMatch = text.includes(value);
                    $el.toggle(isMatch);
                    isVisible = isMatch;
                }

                const $prevTitle = $el.prevAll(".nav-item_title").first();
                if ($prevTitle.length && isVisible) $prevTitle.show();
            });
        });




        // Aside Mini Toggle
        $(".js-aside-toggle").on("click", function () {
            $("body").toggleClass("aside-mini");
            localStorage.setItem(
                "aside-mini",
                $("body").hasClass("aside-mini")
            );
        });

        /* Parent li add class */
        let body = $("body");
        $(".aside-body")
            .find("ul li")
            .parents(".aside-body ul li")
            .addClass("has-sub-item");


        // Prevent inside clicks from bubbling
        $(document).on("click", ".aside-body", function (e) {
            e.stopPropagation();
        });

        // Close all open submenus when clicking outside
        $(document).on("click", function () {
            if ($('body').hasClass('aside-mini')) {
                $(".aside-body .has-sub-item.sub-menu-opened")
                    .removeClass("sub-menu-opened")
                    .children(".aside-submenu.open")
                    .removeClass("open")
                    .slideUp(10);
            }
        });

        /* Submenu Opened */
        $(document).on("click", ".aside-body .has-sub-item > a", function (
            event
        ) {
            event.preventDefault();

            const $currentLi = $(this).parent(".has-sub-item");

            if ($('body').hasClass('aside-mini')) {
                $(".aside-body .has-sub-item")
                    .not($currentLi)
                    .removeClass("sub-menu-opened")
                    .children(".aside-submenu.open")
                    .removeClass("open")
                    .slideUp(10);
            }

            $(this)
                .parent(".has-sub-item")
                .toggleClass("sub-menu-opened");
            if (
                $(this)
                    .siblings("ul")
                    .hasClass("open")
            ) {
                $(this)
                    .siblings("ul")
                    .removeClass("open")
                    .slideUp("10");
            } else {
                $(this)
                    .siblings("ul")
                    .addClass("open")
                    .slideDown("10");
            }
        });

        if ($('body').hasClass('aside-mini')) {
            setTimeout(function () {
                $('.aside-submenu.open')
                    .removeClass('open')
                    .slideUp(0);
            }, 1500); 
        }



        // Handle submenu positioning on scroll
        const updateSubmenuPositions = () => {
            const windowHeight = $(window).height();
            const MENU_PADDING = 30;

            $(".aside-body .aside-nav > li").each(function () {
                const $menuItem = $(this);
                const $subMenu = $menuItem.find(".aside-submenu");

                if (!$subMenu.length) return;

                let menuItemOffsetTop = $menuItem.offset().top - MENU_PADDING;
                const subMenuHeight = $subMenu.outerHeight();

                if (
                    menuItemOffsetTop + subMenuHeight + MENU_PADDING >
                    windowHeight
                ) {
                    const overflow =
                        menuItemOffsetTop +
                        subMenuHeight +
                        MENU_PADDING -
                        windowHeight;
                    menuItemOffsetTop = Math.max(
                        0,
                        menuItemOffsetTop - overflow
                    );
                }

                $subMenu.css("--submenu-dynamic-top", `${menuItemOffsetTop}px`);
            });
        };

        $(".aside .aside-body").on("scroll", updateSubmenuPositions);

        updateSubmenuPositions();

        function shortenFilename(text, maxLength = 15) {
            const extIndex = text.lastIndexOf(".");
            if (extIndex === -1) return text; // No extension found

            const name = text.substring(0, extIndex);
            const ext = text.substring(extIndex);

            if (name.length > maxLength) {
                return name.substring(0, maxLength) + "... " + ext;
            }

            return text;
        }

        $(".shortname").each(function () {
            const originalText = $(this).text();
            $(this).text(shortenFilename(originalText, 10));
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

        // Circle Progress Bar
        let $progressPieChart = $(".progress-pie-chart"),
            percent = parseInt($progressPieChart.data("percent")),
            deg = (360 * percent) / 100;
        if (percent > 50) {
            $progressPieChart.addClass("gt-50");
        }
        $(".ppc-progress-fill").css("transform", "rotate(" + deg + "deg)");
        $(".ppc-percents span").html(percent + "%");

        // Read More Button
        let maxLength = 120;

        $(".js-truncate-text").each(function () {
            let fullText = $(this)
                .html()
                .trim();

            if (fullText.length > maxLength) {
                let shortText = fullText.substring(0, maxLength);
                let remainingText = fullText.substring(maxLength);

                $(this).html(`
                    <span class="short-text">${shortText}</span>
                    <span class="dots">...</span>
                    <span class="full-text" style="display: none;">${remainingText}</span>
                    <span class="read-more-btn pointer text-primary text-decoration-underline">Learn More</span>
                `);
            }
        });

        $(document).on("click", ".read-more-btn", function () {
            let parent = $(this).closest(".fs-12");
            let fullTextElement = parent.find(".full-text");
            let dots = parent.find(".dots");

            if (fullTextElement.is(":visible")) {
                fullTextElement.hide();
                dots.show();
                $(this).text("Learn More");
            } else {
                fullTextElement.show();
                dots.hide();
                $(this).text("Show Less");
            }
        });
    });

    /* Active Menu Open */
    $(window).on("load", function () {
        // Offcanvas
        $(".js-offcanvas-body")
            .empty()
            .html(
                $(".aside-body")
                    .clone()
                    .removeClass("py-4")
                    .addClass("p-0")
            );

        // Handle both original aside and dynamically created offcanvas aside
        const initAsideMenu = $asideBody => {
            $asideBody.find("a.active").each(function () {
                $(this)
                    .closest("li.has-sub-item")
                    .addClass("sub-menu-opened")
                    .children(".aside-submenu")
                    .addClass("open")
                    .show();
            });

            // Scroll to active menu
            const $activeLink = $asideBody.find(".aside-nav > li > a.active");
            if ($activeLink.length) {
                $asideBody.animate(
                    {
                        scrollTop:
                            $activeLink.offset().top -
                            $asideBody.offset().top -
                            100
                    },
                    300
                );
            }
        };

        // Initialize main aside
        initAsideMenu($(".aside-body"));

        // Initialize offcanvas aside after it's created
        let hasInitAsideMenuRun = false;

        $(document).on("shown.bs.offcanvas", "#offcanvasAside", function () {
            if (!hasInitAsideMenuRun) {
                initAsideMenu($(".js-offcanvas-body .aside-body"));
                hasInitAsideMenuRun = true;
            }
        });

        // Handle offcanvas aside
        // Remove aside-mini class on smaller screens
        const handleAsideMini = () => {
            if ($(window).width() < 992) {
                $("body").removeClass("aside-mini");
            }
        };

        // Run on load
        handleAsideMini();

        // Run on window resize
        $(window).on("resize", handleAsideMini);

        // var guideModal = new bootstrap.Modal(document.getElementById('guideModal'));
        // guideModal.show();

        $(".apex-legends > div").each(function () {
            let color = $(this).attr("data-color");
            if (color) {
                $(this).css("--data-color", color);
            }
        });
    });

})(jQuery);


function attachPasswordValidation(form) {
    const passwordInput = form.querySelector(".password-input-element");
    const confirmInput = form.querySelector(".confirm-password-input-element");
    const submitBtn = form.querySelector('[type="submit"]');
    if (!passwordInput || !confirmInput || !submitBtn) return;

    function validate() {
        const password = passwordInput.value.trim();
        const confirm = confirmInput.value.trim();

        const isRequiredValid =
            (!passwordInput.hasAttribute("required") || password !== "") &&
            (!confirmInput.hasAttribute("required") || confirm !== "");
        const isMatch = password === confirm;
        submitBtn.disabled = !(isRequiredValid && isMatch);
    }

    validate();
    passwordInput.addEventListener("input", validate);
    confirmInput.addEventListener("input", validate);
}

document.querySelectorAll(".change-admin-password-form").forEach(attachPasswordValidation);
