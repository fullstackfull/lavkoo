"use strict";

$('#software-update-form').on('submit', function (e) {
    e.preventDefault();
    let formData = new FormData(document.getElementById('software-update-form'));
    let getSoftwareUpdate = $('#get-software-update-route');
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });
    $.ajax({
        type: "POST",
        url: getSoftwareUpdate.data('action'),
        data: formData,
        cache: false,
        contentType: false,
        processData: false,
        beforeSend: function () {
            $('.progress').removeClass('d-none');
            $('#product_form').find('.submit').text('submitting...');
        },
        xhr: function () {
            let xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener("progress",
                (evt) => {
                    if (evt.lengthComputable) {
                        let percentage = (evt.loaded / evt.total) * 100
                        let percentageFormatted = percentage.toFixed(0)
                        $('.progress-bar').css('width', `${percentageFormatted}%`).text(`${percentageFormatted}%`);
                    }
                }, false);
            return xhr;
        },
        success: function (response) {
        },
        complete: function () {
            location.href = getSoftwareUpdate.data('redirect-route') + '/' + $('#update_key').val()
        },
        error: function (xhr, ajaxOption, thrownError) {
        }
    });
});
