$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    const selector = '#description-' + lang + '-editor';
    const $el = $(selector);

    if ($el.length) {
        new Quill(selector, {});
    } else {
        console.warn("Quill editor container not found for:", selector);
    }
});

$(document).off('click', '.auto_fill_title').on('click', '.auto_fill_title', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $nameInput = $('#' + lang + '_name');
    const name = ($nameInput.val() || '').trim();
    const $editorContainer = $('#title-container-' + lang);

    if (name.length === 0) {
        toastMagic.error("Product name is required");
        return;
    }

    let $existingTitle = $button.data('item')?.title ?? "";


    $editorContainer.addClass('outline-animating');
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
            langCode: lang
        },
        success: function (response) {
            $nameInput.val(response.data);
        },
        error: function (xhr, status, error) {
            $editorContainer.removeClass('outline-animating');

            if (xhr.responseJSON && xhr.responseJSON.errors) {
                Object.values(xhr.responseJSON.errors).forEach(fieldErrors => {
                    fieldErrors.forEach(errorMessage => {
                        toastMagic.error(errorMessage);
                    });
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred.');
            }

            $nameInput.val($existingTitle);
            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        },
        complete: function () {
            setTimeout(function () {
                $editorContainer.removeClass('outline-animating');
            }, 500);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        }
    });
});
