'use strict';

$('.addon-publish-status').on('click', function (event) {
    event.preventDefault();
})

$('#addon-upload-form').on('submit', function (event) {
    event.preventDefault();

    const fileInput = $(this).find('input[type="file"]')[0];

    if (!fileInput || fileInput.files.length === 0) {
        toastMagic.error($('#get-file-upload-field-required-message').data('error'));
    } else {
        $.ajaxSetup({
            headers: {
                "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
            },
        });
        let formData = new FormData(document.getElementById('addon-upload-form'));
        $.ajax({
            type: 'POST',
            url: $(this).attr('action'),
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
                let xhr = new window.XMLHttpRequest();
                $('.progress-bar-container').show();
                xhr.upload.addEventListener("progress", function (e) {
                    if (e.lengthComputable) {
                        let percentage = Math.round((e.loaded * 100) / e.total);
                        $(".upload-progress-label").text(percentage + "%");
                        $('.upload-progress-bar').css('width', percentage + '%');
                    }
                }, false);
                return xhr;
            },
            beforeSend: function () {
                $(this).find('[type="reset"]').attr('disabled');
                $(this).find('[type="submit"]').attr('disabled');
            },
            success: function (response) {
                if (response.status === 'error') {
                    $('.progress-bar-container').hide();
                    toastMagic.error(response.message);
                } else if (response.status === 'success') {
                    toastMagic.success(response.message);
                }
                setTimeout(() => {
                    location.reload();
                }, 1000)
            },
            complete: function () {
                $(this).find('[type="reset"]').removeAttr('disabled');
                $(this).find('[type="submit"]').removeAttr('disabled');
            },
            error: function (errors) {
                toastMagic.error(errors?.responseJSON?.message);
            },
        });
    }
});

$(".addon-publish-form").on("submit", function (event) {
    event.preventDefault();

    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
    });
    $.ajax({
        type: 'POST',
        url: $(this).attr("action"),
        data: $(this).serialize(),
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
            if (response.flag === 'inactive') {
                $('#activateData').empty().html(response.view);
                $("#activatedThemeModal").addClass('bg-soft-dark').modal("show");
                if (typeof reinitializeTooltips === 'function') {
                    reinitializeTooltips();
                }
            } else {
                if (response.errors) {
                    for (let i = 0; i < response.errors.length; i++) {
                        setTimeout(() => {
                            toastMagic.error(response.errors[i].message);
                        }, i * 500);
                    }
                } else {
                    toastMagic.success(response?.message);
                    setTimeout(function () {
                        location.reload()
                    }, 3000);
                }
            }
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

$(document).on('submit', '#addon-list-activation-form', function (event) {
    event.preventDefault();

    const $form = $(this);
    const $button = $form.find('.addon-list-activation-submit');
    const $spinner = $button.find('.submit-spinner');
    const $label = $button.find('.submit-label');
    const originalLabel = $label.text();

    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
    });
    $.ajax({
        type: 'POST',
        url: $form.attr('action'),
        data: new FormData(this),
        processData: false,
        contentType: false,
        beforeSend: function () {
            $button.prop('disabled', true);
            $spinner.removeClass('d--none');
            $label.text($label.data('loading-text') || 'Activating...');
        },
        success: function (response) {
            if (response.status === 1 || response.status === 'success') {
                toastMagic.success(response.message);
                setTimeout(function () {
                    location.reload();
                }, 1500);
            } else if (response.errors) {
                for (let i = 0; i < response.errors.length; i++) {
                    setTimeout(() => {
                        toastMagic.error(response.errors[i].message);
                    }, i * 500);
                }
                resetButton();
            } else {
                toastMagic.error(response.message || 'Activation failed');
                resetButton();
            }
        },
        error: function (xhr) {
            const errors = xhr?.responseJSON?.errors;
            if (errors && typeof errors === 'object') {
                const messages = Object.values(errors).flat();
                messages.forEach((msg, i) => {
                    setTimeout(() => toastMagic.error(msg), i * 500);
                });
            } else {
                toastMagic.error(xhr?.responseJSON?.message || 'Activation failed');
            }
            resetButton();
        },
    });

    function resetButton() {
        $button.prop('disabled', false);
        $spinner.addClass('d--none');
        $label.text(originalLabel);
    }
});

$(".addon-delete-form").on("submit", function (event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
    });
    $.ajax({
        type: 'POST',
        url: $(this).attr("action"),
        data: $(this).serialize(),
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (data) {
            if (data.status === 'success') {
                toastMagic.success(data.message);

                setTimeout(function () {
                    location.reload()
                }, 2000);
            } else if (data.status === 'error') {
                toastMagic.error(data.message);
            }
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});
