$(document).ready(function () {
    function getAuctionEditorInstance(lang) {
        const editorElement = document.getElementById('description-' + lang + '-editor');

        if (!editorElement) {
            return null;
        }

        return $(editorElement).data('quill') || Quill.find(editorElement) || null;
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

    $(document).on('click', '.auction-basic-setup-title-generate', function () {
        const $button = $(this);
        const lang = $button.data('lang');
        const route = $button.data('route');
        const $nameInput = $('#' + lang + '_name');
        const $container = $('#auction-title-container-' + lang);
        const currentTitle = ($nameInput.val() || '').trim();
        const existingTitle = $button.data('item')?.title || $nameInput.val() || '';
        let generationSucceeded = false;

        if (!currentTitle.length) {
            toastMagic.error('Auction product name is required to generate title');
            return;
        }

        $container.addClass('outline-animating');
        toggleGeneratingState($button, true);

        $.ajax({
            url: route,
            type: 'GET',
            dataType: 'json',
            data: {
                name: currentTitle,
                langCode: lang
            },
            success: function (response) {
                updateAiRemainingCount(response);
                const generatedTitle = response?.data?.data;

                if (typeof generatedTitle === 'string' && generatedTitle.length) {
                    $nameInput.val(generatedTitle).trigger('input');
                    generationSucceeded = true;
                    return;
                }

                toastMagic.error('Failed to generate auction title.');
            },
            error: function (xhr) {
                $nameInput.val(existingTitle).trigger('input');
                showAuctionAiError(xhr, 'An unexpected error occurred while generating the auction title.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                $(document).trigger('auction-ai-step-complete', [{
                    step: 'title',
                    lang: lang,
                    success: generationSucceeded
                }]);
            }
        });
    });

    $(document).on('click', '.auction-basic-setup-description-generate', function () {
        const $button = $(this);
        const lang = $button.data('lang');
        const route = $button.data('route');
        const $nameInput = $('#' + lang + '_name');
        const $textarea = $('#description-' + lang);
        const $container = $('#auction-description-container-' + lang);
        const quillEditor = getAuctionEditorInstance(lang);
        const currentTitle = ($nameInput.val() || '').trim();
        const existingDescription = $button.data('item')?.description || $textarea.val() || '';
        let generationSucceeded = false;

        if (!currentTitle.length) {
            toastMagic.error('Auction product name is required to generate description');
            return;
        }

        $container.addClass('outline-animating');
        toggleGeneratingState($button, true);

        $.ajax({
            url: route,
            type: 'GET',
            dataType: 'json',
            data: {
                name: currentTitle,
                langCode: lang
            },
            success: function (response) {
                updateAiRemainingCount(response);
                const generatedDescription = response?.data?.data;

                if (typeof generatedDescription !== 'string' || !generatedDescription.length) {
                    toastMagic.error('Failed to generate auction description.');
                    return;
                }

                if (quillEditor) {
                    quillEditor.root.innerHTML = generatedDescription;
                }

                $textarea.val(generatedDescription).trigger('input');
                generationSucceeded = true;
            },
            error: function (xhr) {
                if (quillEditor) {
                    quillEditor.root.innerHTML = existingDescription;
                }
                $textarea.val(existingDescription).trigger('input');
                showAuctionAiError(xhr, 'An unexpected error occurred while generating the auction description.');
            },
            complete: function () {
                setTimeout(function () {
                    $container.removeClass('outline-animating');
                }, 500);

                toggleGeneratingState($button, false);
                $(document).trigger('auction-ai-step-complete', [{
                    step: 'description',
                    lang: lang,
                    success: generationSucceeded
                }]);
            }
        });
    });
});
