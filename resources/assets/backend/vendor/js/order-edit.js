$(document).ready(function () {
    let orderEditSearchTimeout;
    $(document).on('input focus keyup', '.search-product-for-order-edit', function () {
        let $input = $(this);
        let $wrapper = $input.closest('.select-order-edit-product-search');
        let $dropdown = $wrapper.find('.dropdown-menu');
        let name = $input.val();
        clearTimeout(orderEditSearchTimeout);
        if (name.length > 0) {
            orderEditSearchTimeout = setTimeout(function () {
                $dropdown.addClass('show');
                let configEl = document.getElementById('get-search-product-for-edit-order');
                let actionUrl = configEl.getAttribute('data-action');
                let orderId = configEl.getAttribute('data-order-id');

                $.get(actionUrl, {
                    searchValue: name,
                    order_id: orderId,
                }, (response) => {
                    $wrapper.find('.search-result-box').html(response.result);
                });

                $.ajax({
                    url: actionUrl,
                    type: 'GET',
                    data: {
                        searchValue: name,
                        order_id: orderId,
                    },
                    beforeSend: function () {
                        $wrapper.find('.search-result-box').html(
                            '<div class="text-center py-3">' + $('#message-loading-word').data('text') + '...</div>'
                        );
                    },
                    success: function (response) {
                        $wrapper.find('.search-result-box').html(response.result);
                    },
                    error: function (xhr) {
                        $wrapper.find('.search-result-box').html(
                            '<div class="text-danger text-center py-3">Something went wrong</div>'
                        );
                    },
                    complete: function () {
                        // hide loader / enable input
                        // console.log('Request completed');
                    }
                });

            }, 1000);
        } else {
            $dropdown.removeClass('show');
            $('.search-result-box').empty();
        }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.select-order-edit-product-search').length) {
            $('.dropdown-menu').removeClass('show');
        }
    });
    $(document).on('keypress', '.search-product-for-order-edit', function (e) {
        if (e.which === 13) {
            e.preventDefault();
        }
    });

    $('.select-order-edit-product-search').on('click', '.select-order-edit-product-item', function () {
        let productId = $(this).data('product-id');
        let orderId = $(this).data('order-id');

        showEditOrderProductModal(productId, orderId)

    })
});

function showEditOrderProductModal(productId, orderId) {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        url: $('#edit-order-product-modal-view').data("action"),
        method: "POST",
        data: {
            product_id: productId,
            order_id: orderId,
        },
        success: function (response) {
            $('#quick-view').modal('show');
            $('#quick-view-modal').empty().html(response.htmlView);

            initOrderEditProductSliderWithZoom();
        },
    });
}

$(document).on('submit', '.order-edit-add-to-cart-form', function (e) {
    e.preventDefault();

    let form = $(this);

    $.ajax({
        url: form.attr("action"),
        method: "POST",
        data: form.serialize(),
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
        success: function (response) {
            if (response?.status?.toString() === 'success') {
                toastMagic.success(response.message);
                if (response?.product_list_view?.toString() !== '') {
                    updateEditOrderProductsList(response?.product_list_view);
                }
                if (response?.edit_order_total_amount?.toString() !== '') {
                    $('.edit-order-total-amount').empty().text(response.edit_order_total_amount);
                }
                if (response?.submit_button_text?.toString() !== '') {
                    $('.submit-button-text').text(response?.submit_button_text?.toString())
                }
            } else {
                toastMagic.error(response.message);
            }
        },
    });
});


document.addEventListener('change', function (e) {
    const target = e.target;

    if (!target.matches('.order-edit-add-to-cart-form input, .order-edit-add-to-cart-form select')) {
        return;
    }

    const form = target.closest('.order-edit-add-to-cart-form');

    const $form = $(form);
    const qtyInput = $form.find('input[name="quantity"]');
    const qtyButtons = $form.find('.btn-number');

    $.ajax({
        url: form.getAttribute('data-check-variant-price'),
        method: "POST",
        data: $(form).serialize(),
        headers: {
            "X-CSRF-TOKEN": document
                .querySelector('meta[name="_token"]')
                .getAttribute('content'),
        },
        beforeSend: function () {
            qtyInput.prop('disabled', true);
            qtyButtons.prop('disabled', true);
        },
        success: function (response) {
            const el = document.getElementById('product_quick_view_details');
            if (el) {
                el.innerHTML = response.product_quick_view_details;
            } else {
                console.log('not found')
            }
        },
    });
});

function updateEditOrderProductsList(responseHtml) {
    $('#edit-order-products-list').empty().html(responseHtml);
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-number');
    if (!btn) return;

    const input = btn.closest('.product-quantity-group')
        .querySelector('input[name="quantity"]');

    if (!input) return;

    const type = btn.getAttribute('data-type');
    const min = parseInt(input.getAttribute('min')) || 1;
    const max = parseInt(input.getAttribute('max')) || Infinity;

    let value = parseInt(input.value) || min;

    if (type === 'plus' && value < max) value++;
    if (type === 'minus' && value > min) value--;

    input.value = value;
    updateQtyButtons(input);
    input.dispatchEvent(new Event('change', {bubbles: true}));
});

let qtyTypingTimeout;

document.addEventListener('input', function (e) {
    const input = e.target;
    if (!input.matches('input[name="quantity"]')) return;

    // Remove non-numeric characters
    input.value = input.value.replace(/\D/g, '');
    const min = parseInt(input.getAttribute('min')) || 1;
    const max = parseInt(input.getAttribute('max')) || Infinity;
    let value = parseInt(input.value) || min;
    if (value < min) value = min;
    if (value > max) value = max;

    input.value = value;
    updateQtyButtons(input);

    clearTimeout(qtyTypingTimeout);
    qtyTypingTimeout = setTimeout(function () {
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, 100);
});

document.addEventListener('keydown', function (e) {
    if (!e.target.matches('input[name="quantity"]')) return;
    // Allow control keys
    const allowedKeys = [
        'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'
    ];
    if (allowedKeys.includes(e.key)) return;
    // Block non-numeric input
    if (!/^\d$/.test(e.key)) {
        e.preventDefault();
    }
});

function updateQtyButtons(input) {
    const wrapper = input.closest('.product-quantity-group');
    const minusBtn = wrapper.querySelector('[data-type="minus"]');
    const plusBtn = wrapper.querySelector('[data-type="plus"]');

    const value = parseInt(input.value);
    const min = parseInt(input.getAttribute('min')) || 1;
    const max = parseInt(input.getAttribute('max')) || Infinity;

    minusBtn.disabled = value <= min;
    plusBtn.disabled = value >= max;
}


function initOrderEditProductSliderWithZoom() {
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

$(document).on('input', '.update-order-product-form .product-qty', function () {
    updateOrderProductForm(this);
});

$(document).on('click', '.update-order-product-form .qty-count', function () {
    updateOrderProductForm(this);
});

function initTooltips(context = document) {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        context.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            if (bootstrap.Tooltip.getInstance(el)) {
                bootstrap.Tooltip.getInstance(el).dispose();
            }
            new bootstrap.Tooltip(el);
        });
    } else if (typeof $ !== 'undefined' && $.fn.tooltip) {
        $(context).find('[data-toggle="tooltip"]').tooltip('dispose').tooltip();
    }
}

function disposeTooltips(context = document) {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        context.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            const instance = bootstrap.Tooltip.getInstance(el);
            if (instance) instance.dispose();
        });
    } else if (typeof $ !== 'undefined' && $.fn.tooltip) {
        $(context).find('[data-toggle="tooltip"]').tooltip('dispose');
    }
}


function updateOrderProductForm(el) {
    const $form = $(el).closest('.update-order-product-form');
    const $group = $(el).closest('.qty-input-group-design');
    const $inputs = $group.find('button');

    disposeTooltips($form[0]);

    $inputs.prop('disabled', true);
    setTimeout(() => {
        $.ajax({
            url: $form.data('update'),
            type: 'POST',
            data: $(el).closest('.update-order-product-form').serialize(),
            beforeSend() {
                // show loader
            },
            success(response) {
                if (response?.status?.toString() === 'success') {
                    toastMagic.success(response.message, '', true);
                    if (response?.product_list_view?.toString() !== '') {
                        updateEditOrderProductsList(response?.product_list_view);
                    }
                    if (response?.edit_order_total_amount?.toString() !== '') {
                        $('.edit-order-total-amount').empty().text(response.edit_order_total_amount);
                    }
                } else {
                    toastMagic.error(response.message, '', true);
                }
            },
            complete() {
                $inputs.prop('disabled', false);
                initTooltips(document);
            }
        });
    }, 100)
}

$(document).on('click', '.edit-order-product-remove-js', function () {
    const $btn = $(this);
    $btn.prop('disabled', true);

    $.ajax({
        url: $btn.data('route'),
        type: 'POST',
        data: {
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success(response) {
            if (response?.status?.toString() === 'success') {
                toastMagic.success(response.message, '', true);
                if (response?.product_list_view?.toString() !== '') {
                    updateEditOrderProductsList(response.product_list_view);
                }
                if (response?.edit_order_total_amount?.toString() !== '') {
                    $('.edit-order-total-amount').empty().text(response.edit_order_total_amount);
                }
            } else {
                toastMagic.error(response.message, '', true);
            }
        },
        complete() {
            $btn.prop('disabled', false);
        }
    });
});


document.addEventListener('click', function (e) {
    const btn = e.target.closest('.qty-input-group-js .qty-count');
    if (!btn) return;

    const group = btn.closest('.qty-input-group-js');
    const input = group.querySelector('.product-qty');
    if (!input) return;

    const action = btn.dataset.action;
    const min = parseInt(input.min) || 1;
    const max = parseInt(input.max) || Infinity;

    let value = parseInt(input.value) || min;

    if (action === 'plus' && value < max) value++;
    if (action === 'minus' && value > min) value--;

    input.value = value;
    updateQtyButtons(group, value, min, max);

    // trigger ajax listeners
    input.dispatchEvent(new Event('input', {bubbles: true}));
});

document.addEventListener('input', function (e) {
    const input = e.target;
    if (!input.classList.contains('product-qty')) return;

    const group = input.closest('.qty-input-group-js');
    const min = parseInt(input.min) || 1;
    const max = parseInt(input.max) || Infinity;

    let value = parseInt(input.value.replace(/\D/g, '')) || min;

    if (value < min) value = min;
    if (value > max) value = max;

    input.value = value;
    updateQtyButtons(group, value, min, max);
});

document.addEventListener('keydown', function (e) {
    const input = e.target;
    if (!input.classList.contains('product-qty')) return;

    const allowedKeys = [
        'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'
    ];

    if (allowedKeys.includes(e.key)) return;

    if (!/^\d$/.test(e.key)) {
        e.preventDefault();
    }
});


$(document).on('submit', '.update-order-product-form', function (e) {
    e.preventDefault();

    const $form = $(this);
    const $btn = $form.find('.update-cart-btn');
    const $text = $btn.find('span');

    if ($btn.prop('disabled')) return;

    const originalText = $text.text();
    const loadingText = $btn.data('loading-text') || 'Processing';

    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),

        beforeSend() {
            $btn.prop('disabled', true);
            $text.html(`
                <span class="spinner-border spinner-border-sm me-1"></span>
                ${loadingText}
            `);
        },
        success(response) {
            if (response?.status?.toString() === 'success') {
                toastMagic.success(response.message, '', true);

                if (response?.redirect_url) {
                    setTimeout(() => {
                        window.location.href = response.redirect_url;
                    }, 1000);
                }
            } else {
                toastMagic.error(response.message, '', true);
            }
        },

        error(xhr) {
            toastMagic.error(
                xhr.responseJSON?.message || 'Something went wrong',
                '',
                true
            );
        },

        complete() {
            $btn.prop('disabled', false);
            $text.text(originalText);
        }
    });
});
