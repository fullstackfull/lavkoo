$(document).on('click', '.auto_fill_title', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const $nameInput = $('#' + lang + '_name');
    const currentValue =  ($nameInput.val() || '').trim();
    const $editorContainer = $('#title-container-' + lang);

    if (!currentValue || currentValue.trim().length === 0) {
        toastMagic.error("Product name is required");
        return;
    }

    let existingTitle = $button.data('item')?.title ?? '';


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
            name: currentValue,
            langCode: lang
        },
        success: function (response) {
            if (response.data && response.data.data) {
                $nameInput.val(response.data.data);
            }
            if (response.data.remaining_count !== undefined) {
                const remaining = response.data.remaining_count ?? 0;
                $('#ai-remaining-count #count').text(remaining);

            }
        },
        error: function (xhr) {
            $nameInput.val(existingTitle);

            if (xhr.responseJSON && xhr.responseJSON.errors) {
                Object.values(xhr.responseJSON.errors).forEach(fieldErrors => {
                    fieldErrors.forEach(errorMessage => toastMagic.error(errorMessage));
                });
            } else if (xhr.responseJSON && xhr.responseJSON.message) {
                toastMagic.error(xhr.responseJSON.message);
            } else {
                toastMagic.error('An unexpected error occurred.');
            }
        },
        complete: function () {
            setTimeout(() => {
                $editorContainer.removeClass('outline-animating');
            }, 500);

            $button.prop('disabled', false);
            $button.find('.btn-text').text('Re-generate');
            $aiText.addClass('d-none').removeClass('ai-text-animation-visible');
        }
    });
});
