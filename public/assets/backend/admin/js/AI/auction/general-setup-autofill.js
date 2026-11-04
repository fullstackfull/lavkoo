$(document).ready(function () {
    function getDefaultAuctionLanguageCode() {
        const $defaultInput = $('.product-title-default-language').first();

        if (!$defaultInput.length) {
            return null;
        }

        const inputId = $defaultInput.attr('id') || '';
        return inputId.replace(/_name$/, '');
    }

    function getDefaultAuctionDescription() {
        const lang = getDefaultAuctionLanguageCode();

        if (!lang) {
            return '';
        }

        const editorElement = document.getElementById('description-' + lang + '-editor');
        const textareaValue = $('#description-' + lang).val() || '';

        if (!editorElement) {
            return textareaValue;
        }

        const quillEditor = $(editorElement).data('quill') || Quill.find(editorElement);
        return quillEditor ? quillEditor.root.innerHTML : textareaValue;
    }

    function toggleGeneratingState($button, isGenerating) {
        const $aiText = $button.find('.ai-text-animation');
        const $buttonText = $button.find('.btn-text');

        $button.prop('disabled', isGenerating);

        if (isGenerating) {
            $buttonText.text('');
            $aiText.removeClass('d-none').addClass('ai-text-animation-visible');
            return;
        }

        $buttonText.text('Re-generate');
        $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
    }

    function showAuctionAiError(xhr, fallbackMessage) {
        if (xhr.responseJSON && xhr.responseJSON.errors) {
            Object.values(xhr.responseJSON.errors).forEach(function (fieldErrors) {
                fieldErrors.forEach(function (message) {
                    toastMagic.error(message);
                });
            });
            return;
        }

        if (xhr.responseJSON && xhr.responseJSON.message) {
            toastMagic.error(xhr.responseJSON.message);
            return;
        }

        toastMagic.error(fallbackMessage);
    }

    function updateAiRemainingCount(response) {
        const remaining = response?.data?.remaining_count;

        if (typeof remaining === "number") {
            $("#ai-remaining-count").find("#count").text(remaining);
        }
    }

    $(document).on('click', '.auction-general-setup-generate', function () {
        const $button = $(this);
        const route = $button.data('route');
        const $container = $('.auction-general-setup-section').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();
        const existingData = $button.data('item') || {};
        let generationSucceeded = false;

        if (!title.length) {
            toastMagic.error('Auction product name is required to generate general setup');
            return;
        }

        $container.addClass('outline-animating');
        toggleGeneratingState($button, true);

        $.ajax({
            url: route,
            type: 'GET',
            dataType: 'json',
            data: {
                name: title,
                description: description
            },
            success: function (response) {
                updateAiRemainingCount(response);
                const data = response?.data?.data;

                if (!data || typeof data !== 'object') {
                    toastMagic.error('Failed to generate auction general setup.');
                    return;
                }

                if (data.product_type) {
                    $('#product_type').val(data.product_type).trigger('change');
                }

                if (data.category_id) {
                    $('#category_id').val(data.category_id).trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'brand_id')) {
                    $('#brand_id').val(data.brand_id || '').trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'item_condition')) {
                    $('#item_condition').val(data.item_condition || '').trigger('input');
                }

                generationSucceeded = true;
            },
            error: function (xhr) {
                if (Object.prototype.hasOwnProperty.call(existingData, 'product_type')) {
                    $('#product_type').val(existingData.product_type || 'physical').trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'category_id')) {
                    $('#category_id').val(existingData.category_id || '').trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'brand_id')) {
                    $('#brand_id').val(existingData.brand_id || '').trigger('change');
                }

                if (Object.prototype.hasOwnProperty.call(existingData, 'item_condition')) {
                    $('#item_condition').val(existingData.item_condition || '').trigger('input');
                }

                showAuctionAiError(xhr, 'An unexpected error occurred while generating the auction general setup.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                $(document).trigger('auction-ai-step-complete', [{
                    step: 'general-setup',
                    success: generationSucceeded
                }]);
            }
        });
    });
});
