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

    $(document).on('click', '.auction-basic-setup-title-generate', function () {
        const $button = $(this);
        const lang = $button.data('lang');
        const route = $button.data('route');
        const $nameInput = $('#' + lang + '_name');
        const $container = $('#auction-title-container-' + lang);
        const currentTitle = ($nameInput.val() || '').trim();
        const existingTitle = $nameInput.val() || '';

        if (!currentTitle.length) {
            showErrorToast('Auction product name is required to generate title');
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
                    return;
                }

                showErrorToast('Failed to generate auction title.');
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
            }
        });
    });

    $(document).on('click', '.auction-basic-setup-description-generate', function () {
        const $button = $(this);
        let stepSucceeded = false;
        const lang = $button.data('lang');
        const route = $button.data('route');
        const $nameInput = $('#' + lang + '_name');
        const $textarea = $('#description-' + lang);
        const $container = $('#auction-description-container-' + lang);
        const quillEditor = getAuctionEditorInstance(lang);
        const currentTitle = ($nameInput.val() || '').trim();
        const existingDescription = $textarea.val() || '';

        if (!currentTitle.length) {
            showErrorToast('Auction product name is required to generate description');
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
                    showErrorToast('Failed to generate auction description.');
                    return;
                }

                if (quillEditor) {
                    quillEditor.root.innerHTML = generatedDescription;
                }

                $textarea.val(generatedDescription).trigger('input');
                stepSucceeded = true;
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
                // Notify the AI sidebar cascade that this step finished.
                $(document).trigger('auction-ai-step-complete', [{ step: 'description', success: stepSucceeded }]);
            }
        });
    });
});
