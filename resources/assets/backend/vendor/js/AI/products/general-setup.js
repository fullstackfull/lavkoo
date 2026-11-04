
$(document).on('click', '.general_setup_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_name').val();
    const $editor = $('#description-' + lang + '-editor');
    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';
    const $container = $('.general_wrapper').find('.outline-wrapper');
    const $wrapper = $button.closest('.general_wrapper');


    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');



    const existingData = {};
    $wrapper.find('input, select, textarea').each(function () {
        const $field = $(this);
        const fieldName = $field.attr('name');
        if (!fieldName) return;
        if ($field.is('select[multiple]')) {
            existingData[fieldName] = $field.val() || [];
        } else {
            existingData[fieldName] = $field.val();
        }
    });

    $button.data('item', existingData);

    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: {
            name: name,
            description: description,
        },
        success: function (response) {
            var data = response.data.data;
            if (data.category_id) {
                $('#category_id').val(data.category_id).trigger('change');
            }
            if (data.sub_category_id) {
                setTimeout(function () {
                    $('#sub-category-select')
                        .val(data.sub_category_id)
                        .trigger('change');
                }, 1000);
            }
            if (data.sub_sub_category_id) {
                setTimeout(function () {
                    $('#sub-sub-category-select')
                        .val(data.sub_sub_category_id)
                        .trigger('change');
                }, 1500);
            }

            if (data.brand_id) {
                $('#brand_id')
                    .val(data.brand_id)
                    .trigger('change');
            }
            if (data.product_type) {
                $('#product_type').val(data.product_type).trigger('change');
                getProductTypeFunctionality();
            }
            let normalizedProductType = (data.product_type || "").toLowerCase().trim();
            let normalizedDeliveryType = (data.delivery_type || "").toLowerCase().trim();
            if (data.brand_id) $('#brand_id').val(data.brand_id).trigger('change');
            if (normalizedProductType === "digital" && normalizedDeliveryType) {
                const $select = $('#digital_product_type');
                const availableValues = $select.find('option').map(function () {
                    return $(this).val();
                }).get();

                if (availableValues.includes(data.delivery_type)) {
                    $select.val(data.delivery_type).trigger('change');
                }
            }
            if (data.unit_name) {
                $('#unit').val(data.unit_name).trigger('change');
            }

            if (data.search_tags) {
                var $tagsInput = $('#tags');
                $tagsInput.tagsinput('removeAll');
                data.search_tags.forEach(function(tag) {
                    $tagsInput.tagsinput('add', tag);
                });
            }

            let getElement = $('#generate_number');
            $(getElement).val(generateRandomString(6));
            generateSKUPlaceHolder();

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

function generateSKUPlaceHolder() {
    let newPlaceholderValue =
        $("#get-example-text").data("example") +
        " : " +
        $("input[name=code]").val() +
        "-MCU-47-V593-M";
    $(".store-keeping-unit").attr("placeholder", newPlaceholderValue);
}

function generateRandomString(length) {
    let result = "";
    let characters = "012345ABCDEFGHIJKLMNOPQRSTUVWXYZ3456789";
    let charactersLength = characters.length;
    for (let i = 0; i < length; i++) {
        result += characters.charAt(
            Math.floor(Math.random() * charactersLength)
        );
    }
    return result;
}
