$(document).on('click', '.price_others_auto_fill', function () {
    let $select = $('.custom-select');
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_name').val();

    const $editor = $('#description-' + lang + '-editor');
    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';

    const $container = $('.price_wrapper').find('.outline-wrapper');
    const $wrapper = $button.closest('.price_wrapper');
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
            console.log('Success:', response);

            var data = response.data.data;
            if (data.discount_type) {
                let discountType = data.discount_type;
                if ($('#discount_type option[value="' + discountType + '"]').length === 0) {
                    $('#discount_type').append(new Option(discountType, discountType, true, true));
                }
                $('#discount_type').val(discountType).trigger('change');
            }
            if (typeof data.discount_amount !== 'undefined') {
                $('#discount').val(data.discount_amount);
            }
            if (typeof data.current_stock !== 'undefined') {
                $('#current_stock').val(data.current_stock);
            }
            if (typeof data.minimum_order_quantity !== 'undefined') {
                $('#minimum_order_qty').val(data.minimum_order_quantity);
            }
            if (typeof data.shipping_cost !== 'undefined') {
                $('#shipping_cost').val(data.shipping_cost);
            }
            if (typeof data.unit_price !== 'undefined' && data.unit_price !== null) {
                $('#unit_price').val(data.unit_price);
            }
            if (typeof data.is_shipping_cost_multil !== 'undefined') {
                $('#is_shipping_cost_multil').prop('checked', !!data.is_shipping_cost_multil);
            }
            if (Array.isArray(data.vatTax)) {
                let $select = $('.multiple-select-tax-input');
                $select.empty();
                data.vatTax.forEach(function (tax) {
                    let option = new Option(tax.name, tax.id, true, true);
                    $select.append(option);
                });
                $select.trigger('change.select2');
            }

            const remaining = response.data.remaining_count ?? 0;
            $('#ai-remaining-count #count').text(remaining);

        },

        error: function (xhr, status, error) {
            const previousData = $button.data('item');
            Object.keys(previousData).forEach(key => {
                const $field = $wrapper.find(`[name="${key}"]`);
                if ($field.length) {
                    if ($field.is('select[multiple]')) {
                        $field.val(previousData[key]).trigger('change');
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
            setTimeout(function () {
                $container.removeClass('outline-animating');
                $container.find('.bg-animate').removeClass('active');
            }, 500);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        }
    });


});
