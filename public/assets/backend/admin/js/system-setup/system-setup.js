"use strict";

$('.default-language-delete-alert').on('click', function () {
    let messageContainer = $('#get-language-warning-message');
    if ($(this).data('code')?.toString() === 'en') {
        toastMagic.warning(messageContainer.data('title'), messageContainer.data('english'));
    } else {
        toastMagic.warning(messageContainer.data('title'), messageContainer.data('message'));
    }
})

$('.mark-as-default-language-alert').on('click', function () {
    let messageContainer = $('#get-mark-default-warning-message');
    toastMagic.warning(messageContainer.data('title'), messageContainer.data('message'));
})

$('.default-currency-delete-alert').on('click', function () {
    let messageContainer = $('#get-currency-warning-message');
    toastMagic.warning(messageContainer.data('title'), messageContainer.data('message'));
})

$('.mark-as-default-currency').on('click', function () {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        url: $('.mark-as-default-currency').data('ajax'),
        method: 'POST',
        data: {
            _token: $('meta[name="_token"]').attr("content"),
            code: $(this).data('code'),
        },
        success: function (response) {
            if (response?.mode?.toString() === 'multi_currency') {
                $('#defaultCurrencyChangeModal').find('.modal-body').html(response?.view);
                $('#defaultCurrencyChangeModal').modal('show');
            } else {
                toastMagic.success(response.message);
                setTimeout(() => {
                    location.reload();
                }, 2000)
            }
        },
        error: function (response) {
            if (response.status === 422) {
                let errors = response?.responseJSON?.errors;
                Object.keys(errors).forEach(function (key) {
                    toastMagic.error(errors[key][0]);
                });
            } else {
                toastMagic.error(response?.responseJSON?.message || 'Something went wrong!');
            }
        }
    });
});

$('.clean-database-form').on('submit', function (event) {
    const checkboxes = $(this).find('input[name="tables[]"]:checked');
    if (checkboxes.length === 0) {
        event.preventDefault();
        let cleanDatabaseFormMsg = $('.clean-database-form-msg');
        toastMagic.warning(cleanDatabaseFormMsg?.data('warning'), cleanDatabaseFormMsg?.data('warning-msg'), true);
    }
});


$('#software-update-form').on('submit', function (event) {
    event.preventDefault();
    if ($('[name="update_file"]').val()) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        let redirectRoute = $(this).data('redirect-route');
        let formData = new FormData(document.getElementById('software-update-form'));
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
                if (response?.status) {
                    toastMagic.success(response?.message);
                    location.href = redirectRoute;
                } else {
                    toastMagic.error(response?.message);
                    setTimeout(() => {
                        location.reload();
                    }, 2000)
                }
            },
            complete: function () {
                $(this).find('[type="reset"]').removeAttr('disabled');
                $(this).find('[type="submit"]').removeAttr('disabled');
            },
            error: function (errors) {
                toastMagic.error(errors?.responseJSON?.message);
            },
        });
    } else {
        toastMagic.error($(this).data('input-warning'));
    }
});
