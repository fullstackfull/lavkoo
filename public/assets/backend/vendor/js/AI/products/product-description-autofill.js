$(document).on('click', '.auto_fill_description', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_name').val();

    const $editorContainer = $('#editor-container-' + lang);
    const $editor = $('#description-' + lang + '-editor');
    const $textarea = $('#description-' + lang);
    const quillEditor = Quill.find($editor[0]);

    if (name.length === 0) {
        toastMagic.error("Product name is required to generate description");
        return;
    }

    let existingDescription = $button.data('item')?.description || $textarea.val();

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
            if (quillEditor) {
                quillEditor.root.innerHTML = response.data.data;
                $textarea.val(response.data.data);

                if ($textarea.hasClass('product-description-default-language')) {
                    let textWithoutTagsAndEntities = response.data.data.replace(/<[^>]+>|&[^;]+;/g, '');
                    const maxLength = 160;
                    if (textWithoutTagsAndEntities.length > maxLength) {
                        textWithoutTagsAndEntities = textWithoutTagsAndEntities.substring(0, maxLength);
                    }
                    $('#meta_description').val(textWithoutTagsAndEntities);
                }
            }

            const remaining = response.data.remaining_count ?? 0;
            $('#ai-remaining-count #count').text(remaining);

        },
        error: function (xhr, status, error) {
            if (quillEditor) {
                quillEditor.root.innerHTML = $existingDescription;
                $textarea.val($existingDescription);
            }

            if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred.');
            }
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
