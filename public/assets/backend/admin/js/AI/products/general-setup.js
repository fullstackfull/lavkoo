$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    new Quill('#description-' + lang + '-editor', {
    });
});

$(document).on('click', '.general_setup_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $wrapper = $button.closest('.general_wrapper');

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
            let data = response.data;

            if(data.product_type){
                $('#product_type').val(data.product_type).trigger('change');
                getProductTypeFunctionality();
            }
            if (data.category_id) $('#category_id').val(data.category_id).trigger('change');

            if (data.sub_category_id) {
                setTimeout(() => $('#sub-category-select').val(data.sub_category_id).trigger('change'), 1000);
            }

            if (data.sub_sub_category_id) {
                setTimeout(() => $('#sub-sub-category-select').val(data.sub_sub_category_id).trigger('change'), 1500);
            }
            let normalizedProductType = (data.product_type || "").toLowerCase().trim();
            if (data.brand_id) $('#brand_id').val(data.brand_id).trigger('change');
            let normalizedDeliveryType = (data?.delivery_type || "")?.toLowerCase().trim();
            if (normalizedProductType === "digital" && normalizedDeliveryType) {
                const $select = $('#digital-product-type-input');
                const availableValues = $select.find('option').map(function () {
                    return $(this).val();
                }).get();

                if (availableValues.includes(data.delivery_type)) {
                    $select.val(data.delivery_type).trigger('change');
                }
            }
            if (data.unit_name) $('#unit').val(data.unit_name).trigger('change');

            if (data.search_tags) {
                const $tagsInput = $('#tags');
                $tagsInput.tagsinput('removeAll');
                data.search_tags.forEach(tag => $tagsInput.tagsinput('add', tag));
            }

            let getElement = $('#generate-sku-code');
            $(getElement).val(generateRandomString(6));
            generateSKUPlaceHolder();
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
