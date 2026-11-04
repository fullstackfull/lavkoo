$(document).ready(function () {
    function showErrorToast(message) {
        if (typeof toastMagic !== 'undefined' && typeof toastMagic.error === 'function') {
            toastMagic.error(message);
            return;
        }

        if (typeof toastr !== 'undefined' && typeof toastr.error === 'function') {
            toastr.error(message);
            return;
        }

        console.error(message);
    }

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
        const messages = [];

        // Tolerate every backend error shape: [{ error_code, message }],
        // { field: ['msg'] }, ['msg'], { field: 'msg' }, or a bare message.
        const collect = function (value) {
            if (value == null) {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(collect);
                return;
            }
            if (typeof value === 'object') {
                if (typeof value.message === 'string') {
                    messages.push(value.message);
                    return;
                }
                Object.values(value).forEach(collect);
                return;
            }
            messages.push(String(value));
        };

        if (xhr && xhr.responseJSON && xhr.responseJSON.errors) {
            collect(xhr.responseJSON.errors);
        }

        if (!messages.length && xhr && xhr.responseJSON && xhr.responseJSON.message) {
            messages.push(xhr.responseJSON.message);
        }

        if (!messages.length) {
            messages.push(fallbackMessage);
        }

        messages.forEach(showErrorToast);
    }

    function updateAiRemainingCount(response) {
        const remaining = response?.data?.remaining_count;

        if (typeof remaining === "number") {
            $("#ai-remaining-count").find("#count").text(remaining);
        }
    }

    $(document).on('click', '.auction-general-setup-generate', function () {
        const $button = $(this);
        let stepSucceeded = false;
        const route = $button.data('route');
        const $container = $('.auction-general-setup-section').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();

        if (!title.length) {
            showErrorToast('Auction product name is required to generate general setup');
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
                    showErrorToast('Failed to generate auction general setup.');
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

                stepSucceeded = true;
            },
            error: function (xhr) {
                showAuctionAiError(xhr, 'An unexpected error occurred while generating the auction general setup.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                // Notify the AI sidebar cascade that this step finished.
                $(document).trigger('auction-ai-step-complete', [{ step: 'general-setup', success: stepSucceeded }]);
            }
        });
    });
});
