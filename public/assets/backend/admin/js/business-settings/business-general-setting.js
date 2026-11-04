'use strict';

$("#update-error-message").hide();

$("#update-button-message").click(function () {
    $("#update-error-message").slideDown();
});

$('#free-delivery-responsibility').on('change', function () {
    let getAmountAdminArea = $('#free-delivery-over-amount-admin-area');
    if ($(this).val() === 'admin') {
        getAmountAdminArea.fadeIn();
    } else {
        getAmountAdminArea.fadeOut();
    }
});

$('#background-color').on('change', function () {
    let background_color = $('#background-color').val();
    $('#background-color-set').text(background_color);
});

$('#text-color').on('change', function () {
    let text_color = $('#text-color').val();
    $('#text-color-set').text(text_color);
});

$('#maintenance-mode-form').on('submit', function (e) {
    let maintenanceModeForm = $('#maintenance-mode-form');
    e.preventDefault();
    if ($('#get-application-environment-mode').data('value') === 'demo') {
        callDemo()
        setTimeout(() => {
            location.reload();
        }, 3000);
    } else {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
            }
        });
        $.ajax({
            url: maintenanceModeForm.attr('action'),
            method: maintenanceModeForm.attr('method'),
            data: maintenanceModeForm.serialize(),
            beforeSend: function () {
                $('#loading').fadeIn();
            },
            success: function (data) {
                if (data.status?.toString() === 'success') {
                    toastMagic.success(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 2000)
                } else {
                    toastMagic.error(data.message);
                }
            },
            complete: function () {
                $('#loading').fadeOut();
            },
        });
    }
});

$('#update-settings').on('submit', function (e) {
    let minimum_add_fund_amount = parseFloat($('#minimum_add_fund_amount').val());
    let maximum_add_fund_amount = parseFloat($('#maximum_add_fund_amount').val());
    if (maximum_add_fund_amount < minimum_add_fund_amount) {
        e.preventDefault();
        toastMagic.error($('#get-minimum-amount-message').data('error'));
    }
});

$(document).on('click', '.edit', function () {
    let route = $(this).attr("data-id");
    $.ajax({
        url: route,
        type: "GET",
        data: {"_token": "{{ csrf_token() }}"},
        dataType: "json",
        success: function (data) {
            $("#question-filed").val(data.question);
            $("#answer-field").val(data.answer);
            $("#ranking-field").val(data.ranking);
            $("#update-form-submit").attr("action", route);
        }
    });
});
