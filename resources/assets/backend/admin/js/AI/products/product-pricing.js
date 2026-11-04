$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    new Quill('#description-' + lang + '-editor', {
    });
});

$(document).on('click', '.price_others_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $wrapper = $button.closest('.price_wrapper');

    const $editor = $('#description-' + lang + '-editor');
    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';
    const name = $('#' + lang + '_name').val();

    if (!name) {
        toastMagic.error("Product name is required");
        return;
    }

    const existingData = {};
    $wrapper.find('input, select, textarea').each(function () {
        const $field = $(this);
        const fieldName = $field.attr('name');
        if (!fieldName) return;

        if ($field.is('select[multiple]')) {
            existingData[fieldName] = $field.val() || [];
        } else if ($field.is(':checkbox')) {
            existingData[fieldName] = $field.prop('checked');
        } else {
            existingData[fieldName] = $field.val();
        }
    });

    $button.data('item', existingData);

    const $container = $wrapper.find('.outline-wrapper');
    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');


    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: {
            name: name,
            description: description,
        },
        success: function (response) {
            const data = response.data || {};

            if (data.discount_type) {
                let discountType = data.discount_type;
                if ($('#product-discount-type option[value="' + discountType + '"]').length === 0) {
                    $('#product-discount-type').append(new Option(discountType, discountType, true, true));
                }
                $('#product-discount-type').val(discountType).trigger('change');
            }

            if (typeof data.discount_amount !== 'undefined' && data.discount_amount !== null) {
                $('#discount').val(data.discount_amount);
            }
            if (typeof data.current_stock !== 'undefined' && data.current_stock !== null) {
                $('#current_stock').val(data.current_stock);
            }
            if (typeof data.minimum_order_quantity !== 'undefined' && data.minimum_order_quantity !== null) {
                $('#minimum_order_qty').val(data.minimum_order_quantity);
            }
            if (typeof data.shipping_cost !== 'undefined' && data.shipping_cost !== null) {
                $('#shipping_cost_input').val(data.shipping_cost);
            }
            if (typeof data.unit_price !== 'undefined' && data.unit_price !== null) {
                $('#unit_price').val(data.unit_price);
            }
            if (typeof data.is_shipping_cost_multil !== 'undefined') {
                $('#is_shipping_cost_multil').prop('checked', !!data.is_shipping_cost_multil);
            }

            if (Array.isArray(data.vatTax) && data.vatTax.length > 0) {
                const $select = $('.multiple-select-tax-input');
                $select.empty();
                data.vatTax.forEach(tax => {
                    $select.append(new Option(tax.name, tax.id, true, true));
                });
                $select.trigger('change.select2');

                const updateFn = $select.data("updateMoreTag");
                if (typeof updateFn === "function") setTimeout(updateFn, 50);
            }
        },
        error: function (xhr, status, error) {
            console.warn('Error:', error);

            const previousData = $button.data('item');
            Object.keys(previousData).forEach(key => {
                const $field = $wrapper.find(`[name="${key}"]`);
                if ($field.length) {
                    if ($field.is('select[multiple]')) {
                        $field.val(previousData[key]).trigger('change');
                    } else if ($field.is(':checkbox')) {
                        $field.prop('checked', previousData[key]);
                    } else {
                        $field.val(previousData[key]);
                    }
                }
            });

            if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred.');
            }
        },
        complete: function () {
            setTimeout(() => {
                $container.removeClass('outline-animating');
                $container.find('.bg-animate').removeClass('active');
            }, 500);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        }
    });
});
