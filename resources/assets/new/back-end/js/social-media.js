'use strict' ;
$('#social-media-links').on('submit', async function (event){

    event.preventDefault();
    let getData = $('#get-social-media-links-data');
    if (!await validateFormHelper($(this))) return false;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        url: $(this).attr('action'),
        method: $(this).attr('method'),
        data: $(this).serialize(),
        success: function (response) {
            if (response.status === 'success') {
                toastMagic.success(getData.data('success'));
            }else if (response.status === 'update') {
                toastMagic.success(getData.data('info'));
            }
            location.reload();
            $("#social-media-name").val('').trigger("change");
            $('#link').val('');
            $('#actionBtn').html(getData.data('save'));
            $('#social-media-links').attr('action',getData.data('action'));
        },
        error: function (errors) {
            toastMagic.error(errors.responseJSON.message);
        },
        complete: function () {
            formSubmitCleanup($(this));
        }
    });
})

$('.social-media-status-form').on('submit', function(event){
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        url: $(this).attr('action'),
        method: $(this).attr('method'),
        data: $(this).serialize(),
        success: function () {
            // toastMagic.success($('#get-update-status-message').data('success'));
        }
    });
});

$(document).on('click', '.delete', function () {
    let id = $(this).attr('id');
    let deleteData = $('#get-delete');
    if (confirm(deleteData.data('confirm'))) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
            }
        });
        $.ajax({
            url: deleteData.data('action'),
            method: 'POST',
            data: {id: id},
            success: function () {
                // toastMagic.success(deleteData.data('success'));
            }
        });
    }
});
