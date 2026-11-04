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

    $(document).on('click', '.auction-seo-generate', function () {
        const $button = $(this);
        const route = $button.data('route');
        const $container = $('.seo_wrapper').find('.outline-wrapper').first();
        const $defaultTitleInput = $('.product-title-default-language').first();
        const title = ($defaultTitleInput.val() || '').trim();
        const description = getDefaultAuctionDescription();
        const existingData = $button.data('item') || {};
        let generationSucceeded = false;

        if (!title.length) {
            toastMagic.error('Auction product name is required to generate SEO information');
            return;
        }

        if (!description.plain.length) {
            toastMagic.error('Auction product description is required to generate SEO information');
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
                    toastMagic.error('Failed to generate SEO information.');
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

                generationSucceeded = true;
            },
            error: function (xhr) {
                $('#meta_title').val(existingData.meta_title || '').trigger('input');
                $('#meta_description').val(existingData.meta_description || '').trigger('input');

                if (existingData.meta_index) {
                    $('input[name="meta_index"][value="' + existingData.meta_index + '"]').prop('checked', true);
                }

                $('input[name="meta_no_follow"]').prop('checked', !!existingData.meta_no_follow);
                $('input[name="meta_no_image_index"]').prop('checked', !!existingData.meta_no_image_index);
                $('input[name="meta_no_archive"]').prop('checked', !!existingData.meta_no_archive);
                $('input[name="meta_no_snippet"]').prop('checked', !!existingData.meta_no_snippet);
                $('input[name="meta_max_snippet"]').prop('checked', !!existingData.meta_max_snippet);
                $('input[name="meta_max_video_preview"]').prop('checked', !!existingData.meta_max_video_preview);
                $('input[name="meta_max_image_preview"]').prop('checked', !!existingData.meta_max_image_preview);

                $('input[name="meta_max_snippet_value"]').val(existingData.meta_max_snippet_value ?? -1).trigger('input');
                $('input[name="meta_max_video_preview_value"]').val(existingData.meta_max_video_preview_value ?? -1).trigger('input');
                $('select[name="meta_max_image_preview_value"]').val(existingData.meta_max_image_preview_value || 'large').trigger('change');

                showAuctionAiError(xhr, 'An unexpected error occurred while generating SEO information.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                $(document).trigger('auction-ai-step-complete', [{
                    step: 'seo-section',
                    success: generationSucceeded
                }]);
            }
        });
    });
});
