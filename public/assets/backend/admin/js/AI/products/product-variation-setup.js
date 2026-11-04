$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    new Quill('#description-' + lang + '-editor', {
    });
});

$(document).on('click', '.variation_setup_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_name').val();
    const $editor = $('#description-' + lang + '-editor');
    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';
    const plainDescription = quillEditor ? quillEditor.getText().trim() : '';

    const $wrapper = $('.variation_wrapper');

    if (!name) {
        toastMagic.error("Product name is required");
        return;
    }
    if (!plainDescription) {
        toastMagic.error("Product description is required");
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

    if (!$('.product_variation_toggle').is(':checked')) {
        $('.product_variation_toggle').prop('checked', true);

        const productVariationToggle = $('.product_variation_toggle');
        const $wrapper = productVariationToggle.closest('.variation_wrapper');
        const $content = $wrapper.find('.product_variation_content');

        if (productVariationToggle.is(':checked')) {
            $content.removeClass('d--none').stop(true, true).slideDown(200);
        }
    }

    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: {
            name: name,
            description: description,
        },
        success: function (response) {
            $("#product_form").find("input[name='generated_combinations']").remove();
            $("#product_form").append(`<input type="hidden" name="generated_combinations" value='${JSON.stringify(response.data.genereate_variation)}'>`);


            const myColors = response.data.colors.map(color => ({
                color: color.code,
                text: color.name
            }));
            if (response.data.colors_active) {
                setProductColorForAI(myColors);
            }

            if (response.data.choice_attributes) {
                const selectedValues = response.data.choice_attributes.map(attr => ({
                    id: attr?.id?.toString(),
                    name: attr?.name,
                    variation: Array.isArray(attr?.options) ? attr.options.join(',') : ''
                }));
                setAttributeForAI(selectedValues);
            }

            if (response.data.digital_file_types) {
                const $fileTypeSelect = $('#digital-product-type-select');
                $fileTypeSelect.val(response.data.digital_file_types).trigger('change');

                response.data.digital_file_types.forEach(fileType => {
                    const $extensionInput = $(`input[name^="extensions_options_${fileType}"]`);
                    if ($extensionInput.length && response.data.digital_file_extensions[fileType]) {
                        $extensionInput.val(response.data.digital_file_extensions[fileType].join(','));
                        $extensionInput.tagsinput('refresh');
                    }
                });
            }
        },
        error: function (xhr) {

            const previousData = $button.data('item');
            Object.keys(previousData).forEach(key => {
                const $field = $wrapper.find(`[name="${key}"]`);
                if (!$field.length) return;

                if ($field.is('select[multiple]')) {
                    $field.val(previousData[key]).trigger('change');

                    if (key === 'choice_attributes[]') {
                        const selectedValues = previousData[key].map(id => {
                            const $option = $field.find(`option[value="${id}"]`);
                            return {
                                id: id,
                                name: $option.text(),
                                variation: previousData[`choice_options_${id}[]`] || ''
                            };
                        });
                        $('#customer-choice-options-container').empty();
                        selectedValues.forEach(item => {
                            addMoreCustomerChoiceOptionWithAI(item.id, item.name, item.variation);
                        });
                    }
                } else if ($field.is(':checkbox')) {
                    $field.prop('checked', previousData[key]);
                    if (key === 'colors_active') toggleColorSelector();
                } else {
                    $field.val(previousData[key]);
                }
            });

            toastMagic.error(xhr.responseJSON?.message || 'An unexpected error occurred.');
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


function toggleColorSelector() {
    const isEnabled = $('#product-color-switcher').is(':checked');
    if (isEnabled) {
        $('#colors-selector-input').trigger('change');
        $(".color_image_column").removeClass("d-none");
        setTimeout(() => {
            colorWiseImageFunctionality($('#colors-selector-input'));
        }, 1000);
    } else {
        $(".color_image_column").addClass("d-none");
    }
    $('#colors-selector-input').prop('disabled', !isEnabled);
    if (!isEnabled) {
        $('#colors-selector-input').val(null).trigger('change');
        $("#color-wise-image-area").hide();
    }

    if ($('#product_type').val() === "physical") {
        $('.additional-image-column-section').addClass('col-md-12').removeClass('col-md-6').removeClass('col-md-8');
    } else {
        $('.additional-image-column-section').addClass('col-md-8').removeClass('col-md-6').removeClass('col-md-12');
    }
}

function addMoreCustomerChoiceOptionWithAI(index, name, variation) {
    let nameSplit = name.split(" ").join("");
    let genHtml = `<div class="col-md-6"><div class="form-group">
                <input type="hidden" name="choice_no[]" value="${index}">
                    <label class="form-label">${nameSplit}</label>
                    <input type="text" name="choice[]" value="${nameSplit}" hidden>
                    <div class="">
                        <input type="text" class="form-control alpha-numeric-input" name="choice_options_${index}[]"
                        placeholder="${$("#message-enter-choice-values").data("text")}"
                        data-role="tagsinput" value="${variation}">
                    </div>
                </div>
        </div>`;

    document.getElementById("customer-choice-options-container")
        .insertAdjacentHTML("beforeend", genHtml);

    document.querySelectorAll("input[data-role=tagsinput], select[multiple][data-role=tagsinput]")
        .forEach(function (input) {
            $(input).tagsinput();
        });
}


function setProductColorForAI(colorValues) {
    $('#product-color-switcher').val(1).prop('checked', true);
    toggleColorSelector();

    colorValues.forEach(c => {
        let option = $('#colors-selector-input option[value="' + c.color + '"]');

        if (option.length) {
            option.prop('selected', true);
        } else {
            $('#colors-selector-input').append(
                $('<option>', {
                    value: c.color,
                    'data-color': c.color,
                    text: c.text,
                    selected: true
                })
            );
        }
    });

    $('#colors-selector-input').trigger('change');
    setTimeout(() => {
        if ($("#product-color-switcher").prop("checked")) {
            colorWiseImageFunctionality($("#colors-selector-input"));
            $("#color-wise-image-area").show();
        } else {
            $("#color-wise-image-area").hide();
        }
    }, 100);

    getUpdateSKUFunctionality();
}




function setAttributeForAI(selectedValues) {
    let selectedIds = selectedValues.map(item => item.id);

    $('#product-choice-attributes option')
        .prop('selected', false)
        .filter(function () {
            return selectedIds.includes($(this).val());
        })
        .prop('selected', true)
        .trigger('change');
    $('#customer-choice-options-container').empty();
    selectedValues.forEach(item => {
        addMoreCustomerChoiceOptionWithAI(item.id, item.name, item.variation);
    });
}
