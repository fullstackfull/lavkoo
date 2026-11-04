$('[data-lang]').each(function () {
    const lang = $(this).data('lang');
    new Quill('#description-' + lang + '-editor', {});
});

$(document).on('click', '.seo_section_auto_fill', function () {
    const $button = $(this);
    const lang = $button.data('lang');
    const route = $button.data('route');
    const name = $('#' + lang + '_name').val();
    const $editor = $('#description-' + lang + '-editor');

    const quillEditor = Quill.find($editor[0]);
    const description = quillEditor ? quillEditor.root.innerHTML : '';
    const plainDescription = quillEditor ? quillEditor.getText().trim() : '';

    const $container = $('.seo_wrapper').find('.outline-wrapper');

    if (!name) {
        toastMagic.error("Product name is required");
        return;
    }
    if (!plainDescription) {
        toastMagic.error("Product description is required");
        return;
    }

    const existingData = {};
    $container.find('input, select, textarea').each(function () {
        const $field = $(this);
        const fieldName = $field.attr('name');
        if (!fieldName) return;

        if ($field.is(':checkbox')) {
            existingData[fieldName] = $field.prop('checked');
        } else if ($field.is('select')) {
            existingData[fieldName] = $field.val();
        } else {
            existingData[fieldName] = $field.val();
        }
    });
    $button.data('item', existingData);

    $container.addClass('outline-animating');
    $container.find('.bg-animate').addClass('active');
    $button.prop('disabled', true);
    $button.find('.btn-text').text('');
    const $aiText = $button.find('.ai-text-animation');
    $aiText.removeClass('d-none').addClass('ai-text-animation-visible');

    $.ajax({
        url: route,
        type: 'GET',
        dataType: 'json',
        data: { name, description },
        success: function (response) {
            const data = response.data;

            $('#meta_title').val(data.meta_title);
            $('#meta_description').val(data.meta_description);

            $('input[name="meta_index"][value="' + data.meta_index + '"]').prop('checked', true);
            $('input[name="meta_no_follow"]').prop('checked', data.meta_no_follow == 1);
            $('input[name="meta_no_image_index"]').prop('checked', data.meta_no_image_index == 1);
            $('input[name="meta_no_archive"]').prop('checked', data.meta_no_archive == 1);
            $('input[name="meta_no_snippet"]').prop('checked', data.meta_no_snippet == 1);

            $('input[name="meta_max_snippet"]').prop('checked', data.meta_max_snippet == 1);
            $('input[name="meta_max_video_preview"]').prop('checked', data.meta_max_video_preview == 1);
            $('input[name="meta_max_image_preview"]').prop('checked', data.meta_max_image_preview == 1);

            $('input[name="meta_max_snippet_value"]').val(data.meta_max_snippet_value);
            $('input[name="meta_max_video_preview_value"]').val(data.meta_max_video_preview_value);
            $('select[name="meta_max_image_preview_value"]').val(data.meta_max_image_preview_value);
        },
        error: function (xhr) {
            const previousData = $button.data('item');
            Object.keys(previousData).forEach(key => {
                const $field = $container.find(`[name="${key}"]`);
                if (!$field.length) return;

                if ($field.is(':checkbox')) {
                    $field.prop('checked', previousData[key]);
                } else if ($field.is('select')) {
                    $field.val(previousData[key]).trigger('change');
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
