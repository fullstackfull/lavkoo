"use strict";

$(document).on('ready', function () {
    $('.js-toggle-password').each(function () {
        new HSTogglePassword(this).init()
    });
    $('.js-validate').each(function () {
        $.HSCore.components.HSValidation.init($(this));
    });
});

$('.submit-login-form').on('click',function (){
    {
        $.ajaxSetup({
            headers: {
                'X-XSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        $.post({
            url: $('#vendor-login-form').attr('action'),
            data: $('#vendor-login-form').serialize(),
            beforeSend: function () {
                $('#loading').fadeIn();
            },
            success: function (data) {
                if (data.errors) {
                    for (let index = 0; index < data.errors.length; index++) {
                        setTimeout(() => {
                            toastMagic.error(data.errors[index].message);
                        }, index * 500);
                    }
                } else if(data.error){
                    toastMagic.error(data.error);
                } else if(data.status){
                    $('.'+data.status+'-message').removeClass('d-none')
                } else {
                    location.href = data.redirectRoute;
                    toastMagic.success(data.success)
                }

                if (data.errors) {
                    for (let index = 0;index < data.errors.length; index++) {
                        setTimeout(() => {
                            toastMagic.error(data.errors[index].message);
                        }, index * 500);
                    }
                }
            },
            complete: function () {
                $('#loading').fadeOut();
            },
            error: function (xhr) {
                if (xhr.responseJSON) {
                    const responseErrors = xhr.responseJSON.errors;
                    if (Array.isArray(responseErrors)) {
                        responseErrors.forEach(error => {
                            toastMagic.error(error.message);
                        });
                    } else if (typeof responseErrors === 'object') {
                        for (const key in responseErrors) {
                            if (responseErrors.hasOwnProperty(key)) {
                                toastMagic.error(responseErrors[key]);
                            }
                        }
                    } else if (xhr.responseJSON.error) {
                        toastMagic.error(xhr.responseJSON.error);
                    } else {
                        toastMagic.error('An unexpected error occurred. Please try again.');
                    }
                } else {
                    toastMagic.error('An unknown error occurred.');
                }

                setTimeout(() => {
                    location.reload();
                }, 3000)
            }
        })
    }
})

$('.clear-alter-message').on('click',function (){
    $('.vendor-suspend').addClass('d-none')
})

$('#copyLoginInfo').on('click', function () {
    let vendorEmail = $('#vendor-email').data('email');
    let vendorPassword = $('#vendor-password').data('password');
    $('#signingVendorEmail').val(vendorEmail);
    $('#signingVendorPassword').val(vendorPassword);
    toastMagic.success($('#message-copied_success').data('text'));
});

$('.onerror-logo').on('error', function () {
    let image = $('#onerror-logo').data('onerror-logo');
    $(this).attr('src', image);
});


