"use strict";

$(document).on('ready', function () {
    // INITIALIZATION OF SHOW PASSWORD
    // =======================================================
    $('.js-toggle-password').each(function () {
        new HSTogglePassword(this).init()
    });

    // INITIALIZATION OF FORM VALIDATION
    // =======================================================
    $('.js-validate').each(function () {
        $.HSCore.components.HSValidation.init($(this));
    });
});



$('#copyLoginInfo').on('click', function () {
    let adminEmail = $('#admin-email').data('email');
    let adminPassword = $('#admin-password').data('password');
    $('#signingAdminEmail').val(adminEmail);
    $('#signingAdminPassword').val(adminPassword);
    toastMagic.success($('#message-copied_success').data('text'));
});

$('.onerror-logo').on('error', function () {
    let image = $('#onerror-logo').data('onerror-logo');
    $(this).attr('src', image);
});


