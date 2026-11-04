$(document).ready(function () {
    'use strict'

    // ---- Text Collapse
    function shortenText(text, maxLength = 100) {
        return text.length > maxLength ?
            text.substring(0, maxLength).trim() + "... " :
            text;
    }

    $(".short_text").each(function () {
        const $textEl = $(this);
        const originalText = $textEl.text().replace(/\s+/g, " ").trim();
        const maxLength = parseInt($textEl.data("maxlength")) || 100;

        const croppedText = shortenText(originalText, maxLength);

        $textEl.data("full-text", originalText);
        $textEl.text(croppedText);
    });

    $(".see_more_btn").on("click", function () {
        const $wrapper = $(this).closest(".short_text_wrapper");
        const $textEl = $wrapper.find(".short_text");

        const fullText = $textEl.data("full-text");
        const maxLength = parseInt($textEl.data("maxlength")) || 100;
        const seeMoreText = $textEl.data("see-more-text") || "See More";
        const seeLessText = $textEl.data("see-less-text") || "See Less";

        const isExpanded = $textEl.hasClass("expanded");

        if (isExpanded) {
            // Collapse
            $textEl.text(shortenText(fullText, maxLength)).removeClass("expanded");
            $(this).text(seeMoreText);
        } else {
            // Expand
            $textEl.text(fullText).addClass("expanded");
            $(this).text(seeLessText);
        }
    });

    // ---- swipper slider and zoom
    function initSliderWithZoom() {
        $(".easyzoom").each(function () {
            $(this).easyZoom();
        });

        // Pages with several quick-view sliders (product gallery) initialize each
        // by id with its own navigation; a blanket class init there grabs the
        // first slider with the wrong config and crashes Swiper's thumbs module.
        let quickviewSliders = document.querySelectorAll(".quickviewSlider2");
        let quickviewThumbs = document.querySelectorAll(".quickviewSliderThumb2");
        if (quickviewSliders.length !== 1 || quickviewThumbs.length !== 1 || quickviewSliders[0].swiper) {
            return;
        }

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
})

document.addEventListener("DOMContentLoaded", function () {
    document.addEventListener("input", function (event) {
        const input = event.target;
        if (input.matches('input[type="number"], .no-negative-input')) {
            if (input.value < 0) {
                input.value = input.value.replace(/^-/, ""); // Remove minus sign
            }
        }
    });

    document.addEventListener("keydown", function (event) {
        const input = event.target;
        if (input.matches('input[type="number"], .no-negative-input')) {
            if (event.key === "-" || event.key === "Subtract") {
                event.preventDefault();
            }
        }
    });

    document.addEventListener("keydown", function (event) {
        const input = event.target;
        if (input.classList.contains("only-number-input")) {
            if (
                event.key === "+" ||
                event.key === "-" ||
                event.key === "Subtract" ||
                event.key === "Add" ||
                event.key === "." ||  // block decimal point
                event.key === ","     // block comma (if user tries)
            ) {
                event.preventDefault();
            }
        }
    });
    document.addEventListener("keydown", function (event) {
        const input = event.target;

        if (!input.classList.contains("only-number-input-with-decimal-point")) {
            return;
        }
        if (
            event.key === "Backspace" ||
            event.key === "Delete" ||
            event.key === "ArrowLeft" ||
            event.key === "ArrowRight" ||
            event.key === "Tab"
        ) {
            return;
        }
        if (event.key === "." && input.value.includes(".")) {
            event.preventDefault();
            return;
        }
        if (!/^[0-9.]$/.test(event.key)) {
            event.preventDefault();
        }
    });
    document.addEventListener("input", function (event) {
        const input = event.target;
        if (input.classList.contains("only-number-input")) {
            input.value = input.value.replace(/[^0-9]/g, "");
        }
    });

    document.addEventListener("keydown", function (event) {
        const input = event.target;

        if (input.classList.contains("no-first-space")) {
            if (event.key === " " && input.selectionStart === 0) {
                event.preventDefault();
            }
        }
    });

    document.addEventListener("input", function (event) {
        const input = event.target;
        if (input.classList.contains("no-first-space")) {
            input.value = input.value.replace(/^\s+/, "");
        }
    });

    document.addEventListener("input", function (event) {
        const input = event.target;

        // Allow letters, numbers, dot, space, hyphen, underscore. Blocks
        // special chars like # @ * & $ % ! ( ) [ ] { } / \ etc. — so values
        // such as "text-text", "text_value", or "text-22" remain typeable.
        if (input.classList.contains("alpha-numeric-input")) {
            input.value = input.value.replace(/[^\p{L}\p{N}._\- ]/gu, "");
        }

        // Tagsinput wraps the .alpha-numeric-input and the user actually
        // types into a child input inside .bootstrap-tagsinput, so this
        // branch is what governs values like "size-32".
        if (input.closest(".bootstrap-tagsinput")) {
            input.value = input.value.replace(/[^\p{L}\p{N}._\- ]/gu, "");
        }
    });

    document.addEventListener("keydown", function (event) {
        const input = event.target;

        if (input.classList.contains("alpha-numeric-input")) {
            const allowed =
                event.key.length !== 1 || /^[\p{L}\p{N}._\- ]$/u.test(event.key);

            if (!allowed) {
                event.preventDefault();
            }
        }

        if (input.closest(".bootstrap-tagsinput")) {
            const isSingleChar = event.key.length === 1;
            const isValidChar = /^[\p{L}\p{N}._\- ]$/u.test(event.key);

            if (isSingleChar && !isValidChar) {
                event.preventDefault();
            }
        }
    });
});

// --- Dropdoown with select & search
document.addEventListener("DOMContentLoaded", () => {
    const debounce = (fn, delay = 300) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    };

    document.querySelectorAll(".custom_dropdown_wrapper").forEach(wrapper => {
        const control = wrapper.querySelector(".custom_dropdown_toggle");
        const menu = wrapper.querySelector(".custom_dropdown_menu");
        const searchInput = wrapper.querySelector(".custom_dropdown_search");
        const list = wrapper.querySelector(".custom_dropdown_list");
        const empty = wrapper.querySelector(".custom_dropdown_empty");
        const selectedText = wrapper.querySelector(".selected_text");
        const items = list.querySelectorAll(".custom_dropdown_item");

        const highlightSelected = () => {
            items.forEach(item => {
                if (item.textContent.trim() === selectedText.textContent.trim()) {
                    item.classList.add("text-primary");
                } else {
                    item.classList.remove("text-primary");
                }
            });
        };

        highlightSelected();

        menu.style.display = "none";
        list.style.display = "none";
        empty.style.display = "none";

        control.addEventListener("click", (e) => {
            const isVisible = menu.style.display === "block";
            menu.style.display = isVisible ? "none" : "block";
            searchInput.value = "";
            list.style.display = "none";
            empty.style.display = "none";
            if (!isVisible) {
                setTimeout(() => {
                    searchInput.focus();
                    searchInput.dispatchEvent(new Event("click"));
                }, 50);
            }
        });

        searchInput.addEventListener("focus", () => {
            list.style.display = "block";
            empty.style.display = "none";
            items.forEach(item => item.style.display = "block");
        });

        searchInput.addEventListener("click", (e) => {
            e.stopPropagation();
            list.style.display = "block";
            empty.style.display = "none";
            items.forEach(item => item.style.display = "block");
        });

        searchInput.addEventListener("input", debounce((e) => {
            const query = e.target.value.toLowerCase().trim();
            let hasMatch = false;

            if (!query) {
                list.style.display = "block";
                empty.style.display = "none";
                items.forEach(item => item.style.display = "block");
                highlightSelected();
                return;
            }

            items.forEach(item => {
                if (item.textContent.toLowerCase().includes(query)) {
                    item.style.display = "block";
                    hasMatch = true;
                } else {
                    item.style.display = "none";
                }
            });

            if (hasMatch) {
                list.style.display = "block";
                empty.style.display = "none";
            } else {
                list.style.display = "none";
                empty.style.display = "block";
            }

            highlightSelected();
        }, 300));

        items.forEach(item => {
            item.addEventListener("click", () => {
                selectedText.textContent = item.textContent;
                menu.style.display = "none";
                highlightSelected();
            });
        });

        document.addEventListener("click", (e) => {
            if (!wrapper.contains(e.target)) {
                menu.style.display = "none";
            }
        });
    });
});


// ---- Qty Count ---
$(document).ready(function () {
    $('.qty-input-group').each(function () {
        const $group = $(this);
        const $input = $group.find('.product-qty');
        const min = +$input.attr('min') || 1;
        const max = +$input.attr('max') || 99;

        $group.on('click', '.qty-count', function () {
            let val = +$input.val();
            const isAdd = $(this).data('action') === 'plus';
            val = isAdd ? Math.min(val + 1, max) : Math.max(val - 1, min);
            $input.val(val).trigger('change');
        });

        let typingTimer;
        $input.on('input', function () {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                let val = +$input.val();
                if (isNaN(val) || val < min) val = min;
                if (val > max) val = max;
                $input.val(val).trigger('change');
            }, 400);
        });

        $input.on('change', function () {
            const val = +$input.val();
            $group.find('.qty-count[data-action="minus"]').prop('disabled', val <= min);
            $group.find('.qty-count[data-action="plus"]').prop('disabled', val >= max);
        }).trigger('change');
    });
});


document.querySelectorAll('.action-preview-for-uploaded-image').forEach(input => {

    input.addEventListener('change', function () {
        const file = this.files?.[0];
        if (!file) return;
        const fileURL = URL.createObjectURL(file);
        const previewSelector = this.dataset.previewElements;
        const previewImages = document.querySelectorAll(previewSelector);
        setTimeout(() => {
            const stillHasFile = this.files && this.files[0];
            previewImages.forEach(img => {
                setTimeout(() => {
                    const uploadBox = img.closest('.upload-file');
                    const textbox = uploadBox?.querySelector('.upload-file-textbox');
                    if (!stillHasFile) {
                        img.src = '';
                        img.style.display = "none";
                        img.classList.add("d-none");
                        if (textbox) textbox.classList.remove('d-none');

                        if (uploadBox) uploadBox.classList.remove("active-upload");
                    } else {
                        img.src = fileURL;
                        img.style.display = "block";
                        img.classList.remove("d-none");
                        if (textbox) textbox.classList.add('d-none');

                        if (uploadBox) uploadBox.classList.add("active-upload");
                    }

                    const overlay = uploadBox?.querySelector('.overlay');

                    if (overlay) {
                        if (uploadBox.classList.contains("active-upload")) {
                            overlay.classList.add("show");
                        } else {
                            overlay.classList.remove("show");
                        }
                    }

                }, 20);
            });
        }, 200)
    });
});

$(document).ready(function() {
    $(document).on('submit', '.pos-ajax-form-add-customer', function(e) {
        e.preventDefault();

        let form = $(this);
        let url = form.attr('action');
        let method = form.attr('method') || 'POST';
        let formData = new FormData(this);
        let submitBtn = form.find('[type="submit"]');
        let resetBtn = form.find('[type="reset"]');
        form.find('.invalid-feedback').text('');
        form.find('.form-control').removeClass('is-invalid');
        submitBtn.prop('disabled', true);
        resetBtn.prop('disabled', true);
        $.ajax({
            url: url,
            type: method,
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if(response.success) {
                    form[0].reset();
                    toastMagic.success(response.message);
                    setTimeout(function() {
                        if(form.closest('.offcanvas').length) {
                            let offcanvasEl = form.closest('.offcanvas')[0];
                            let bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
                            if(bsOffcanvas) bsOffcanvas.hide();
                        }
                        if(form.closest('.modal').length) {
                            let modalEl = form.closest('.modal')[0];
                            let bsModal = bootstrap.Modal.getInstance(modalEl);
                            if(bsModal) bsModal.hide();
                        }
                    }, 100);
                    setTimeout(function() {
                        location.reload();
                    }, 1200);
                }
            },
            error: function(xhr) {
                if(xhr.status === 422) {
                    let errors = xhr.responseJSON?.errors || xhr.responseJSON;
                    if (Array.isArray(errors)) {
                        errors.forEach(function(err) {
                            let input = form.find('[name="'+err.error_code+'"]');
                            if (err.error_code === 'phone') {
                                let phoneWrapper = form.find('.iti');
                                let visiblePhoneInput = phoneWrapper.find('.iti__tel-input');
                                let feedbackEl = phoneWrapper.next('.invalid-feedback');
                                visiblePhoneInput.addClass('is-invalid');
                                feedbackEl.css('display', 'block');
                                feedbackEl.text(err.message);
                            } else {
                                input.addClass('is-invalid');
                                let feedbackEl = input.next('.invalid-feedback');
                                if (feedbackEl.length === 0) {
                                    feedbackEl = input.parent().next('.invalid-feedback');
                                }
                                if (feedbackEl.length === 0) {
                                    feedbackEl = input.closest('.form-group').find('.invalid-feedback');
                                }
                                feedbackEl.text(err.message);
                            }
                        });
                    }
                }
                else {
                    console.log('Something went wrong!');
                }
            },
            complete: function() {
                submitBtn.prop('disabled', false);
                resetBtn.prop('disabled', false);
            }
        });
    });
    $(document).on('input change', '.pos-ajax-form-add-customer .form-control', function() {
        $(this).removeClass('is-invalid');
        if ($(this).hasClass('iti__tel-input')) {
            let phoneWrapper = $(this).closest('.iti');
            let feedbackEl = phoneWrapper.next('.invalid-feedback');
            feedbackEl.css('display', 'none');
            feedbackEl.text('');
        } else {
            let feedbackEl = $(this).next('.invalid-feedback');
            if (feedbackEl.length === 0) {
                feedbackEl = $(this).parent().next('.invalid-feedback');
            }
            if (feedbackEl.length === 0) {
                feedbackEl = $(this).closest('.form-group').find('.invalid-feedback');
            }
            feedbackEl.text('');
        }
    });
});

// for featured status change
$(document).ready(function () {
    $('.product-status-checkbox').on('change', function () {
        let $checkbox = $(this);
        let productId = $checkbox.data('id');
        let action = $checkbox.data('action');
        let newStatus = $checkbox.is(':checked') ? 1 : 0;

        $.ajax({
            url: action,
            method: "POST",
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            data: {
                id: productId,
                status: newStatus
            },
            success: function (response) {
                if (response.status == 1) {
                    toastMagic.success(response.message);
                } else {
                    $checkbox.prop('checked', !newStatus);
                    toastMagic.error(response.message);
                }
            },
            error: function (xhr) {
                $checkbox.prop('checked', !newStatus);
                toastMagic.error("Something_went_wrong");
            }
        });
    });

});



// Typing  Show Hide cross icon
$(document).on("input", ".search-with-icon input", function () {
    const wrapper = $(this).closest(".search-with-icon");
    const cross = wrapper.find(".search-cross");
    if ($(this).val().trim().length > 0) {
        cross.removeClass("d-none");
    } else {
        cross.addClass("d-none");
    }
});
$(document).on("click", ".search-cross", function (e) {
    const wrapper = $(this).closest(".search-with-icon");
    const input = wrapper.find("input");
    input.val("");
    $(this).addClass("d-none");
    input.trigger("input");
    input.focus();
});
$(document).ready(function () {
    $(".search-with-icon").each(function () {
        const input = $(this).find("input");
        const cross = $(this).find(".search-cross");
        if (input.val().trim().length > 0) {
            cross.removeClass("d-none");
        } else {
            cross.addClass("d-none");
        }
    });
});
// Typing  Show Hide cross icon  End

// Tooltip interactions.
// On hover-capable devices keep the original mouseenter/mouseleave show/hide.
// On touch devices BS4's default `hover focus` trigger fires on tap (focus
// shows the tooltip) and then synthetic mouseleave hides it on finger-lift.
// A normal click then arrives and would toggle it back on — but if the
// click handler runs after the tooltip has already been hidden by focus
// loss, the user perceives "tap does nothing, only long-press works" (the
// focus is held during the press, and the tooltip stays visible until
// release fires click). Switch BS4's default trigger to `manual` on touch
// so only our explicit click handler controls show/hide.
const _isTouchOnlyDevice = window.matchMedia && window.matchMedia('(hover: none)').matches;

if (_isTouchOnlyDevice) {
    // Override BS4 default BEFORE theme.min.js's $(document).ready calls
    // $('[data-toggle="tooltip"]').tooltip(). common-script.js runs at
    // parse-time (above), which is earlier than any ready callback.
    if (window.jQuery && $.fn.tooltip && $.fn.tooltip.Constructor) {
        $.fn.tooltip.Constructor.Default.trigger = 'manual';
    }
    // Belt-and-braces: if any tooltip was already initialized with the
    // old default (e.g. a re-rendered partial), dispose + reinit so it
    // picks up the manual trigger.
    $(function () {
        $('[data-toggle="tooltip"]').each(function () {
            const $el = $(this);
            try { $el.tooltip('dispose'); } catch (_) {}
            $el.tooltip();
        });
    });

    $(document).on('click', '[data-toggle="tooltip"]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        // Close any other open tooltip first so only one is visible.
        $('[data-toggle="tooltip"]').not(this).tooltip('hide');
        $(this).tooltip('toggle');
    });
    // Tap anywhere outside a tooltip trigger closes any open tooltip.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('[data-toggle="tooltip"], .tooltip').length) {
            $('[data-toggle="tooltip"]').tooltip('hide');
        }
    });
} else {
    $(document).on('mouseenter', '[data-toggle="tooltip"]', function () {
        $(this).tooltip('show');
    }).on('mouseleave', '[data-toggle="tooltip"]', function () {
        $(this).tooltip('hide');
    });
}

// Hide tooltips on any scroll so they don't float once the trigger icon
// has scrolled out from under them. `capture: true` is required so this
// also fires for scrolls inside the v2 .v2-main container (scroll events
// don't bubble, but they do reach window during the capture phase).
// Guard: BS4 tooltip plugin isn't loaded on every page (some screens load
// BS5 only, which doesn't register $.fn.tooltip on jQuery).
const _hideAllJqTooltips = () => {
    if ($.fn.tooltip) $('[data-toggle="tooltip"]').tooltip('hide');
};
window.addEventListener('scroll', _hideAllJqTooltips, true);
window.addEventListener('touchmove', _hideAllJqTooltips, { passive: true });

// Truncate-only tooltips: when an element carries both `.text-truncate`
// and `data-toggle="tooltip"`, only show its tooltip while the text is
// actually clipped (scrollWidth > clientWidth). Avoids a redundant
// tooltip that just repeats text the user can already read in full.
$(document).on('show.bs.tooltip', '.text-truncate[data-toggle="tooltip"]', function (e) {
    if (this.scrollWidth <= this.clientWidth + 1) {
        e.preventDefault();
    }
});
