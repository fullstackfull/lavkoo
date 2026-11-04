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
            return { html: '', plain: '' };
        }

        const editorElement = document.getElementById('description-' + lang + '-editor');
        const textareaValue = $('#description-' + lang).val() || '';

        if (!editorElement) {
            const plainText = textareaValue.replace(/<[^>]+>/g, ' ').trim();
            return { html: textareaValue, plain: plainText };
        }

        const quillEditor = $(editorElement).data('quill') || Quill.find(editorElement);
        if (!quillEditor) {
            const plainText = textareaValue.replace(/<[^>]+>/g, ' ').trim();
            return { html: textareaValue, plain: plainText };
        }

        return {
            html: quillEditor.root.innerHTML,
            plain: quillEditor.getText().trim()
        };
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

    $(document).on('click', '.auction-seo-generate', function () {
        const $button = $(this);
        let stepSucceeded = false;
        const route = $button.data('route');
        const $container = $('.seo_wrapper').find('.outline-wrapper').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();

        if (!title.length) {
            showErrorToast('Auction product name is required to generate SEO information');
            return;
        }

        if (!description.plain.length) {
            showErrorToast('Auction product description is required to generate SEO information');
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
                description: description.html
            },
            success: function (response) {
                updateAiRemainingCount(response);
                const data = response?.data?.data;

                if (!data || typeof data !== 'object') {
                    showErrorToast('Failed to generate SEO information.');
                    return;
                }

                $('#meta_title').val(data.meta_title || '').trigger('input');
                $('#meta_description').val(data.meta_description || '').trigger('input');

                if (data.meta_index) {
                    $('input[name="meta_index"][value="' + data.meta_index + '"]').prop('checked', true);
                }

                $('input[name="meta_no_follow"]').prop('checked', data.meta_no_follow == 1);
                $('input[name="meta_no_image_index"]').prop('checked', data.meta_no_image_index == 1);
                $('input[name="meta_no_archive"]').prop('checked', data.meta_no_archive == 1);
                $('input[name="meta_no_snippet"]').prop('checked', data.meta_no_snippet == 1);
                $('input[name="meta_max_snippet"]').prop('checked', data.meta_max_snippet == 1);
                $('input[name="meta_max_video_preview"]').prop('checked', data.meta_max_video_preview == 1);
                $('input[name="meta_max_image_preview"]').prop('checked', data.meta_max_image_preview == 1);

                $('input[name="meta_max_snippet_value"]').val(data.meta_max_snippet_value ?? -1).trigger('input');
                $('input[name="meta_max_video_preview_value"]').val(data.meta_max_video_preview_value ?? -1).trigger('input');

                if (Object.prototype.hasOwnProperty.call(data, 'meta_max_image_preview_value')) {
                    $('select[name="meta_max_image_preview_value"]').val(data.meta_max_image_preview_value || 'large').trigger('change');
                }

                stepSucceeded = true;
            },
            error: function (xhr) {
                showAuctionAiError(xhr, 'An unexpected error occurred while generating SEO information.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                // Notify the AI sidebar cascade that this step finished.
                $(document).trigger('auction-ai-step-complete', [{ step: 'seo-section', success: stepSucceeded }]);
            }
        });
    });
});
