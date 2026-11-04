"use strict";

$('.reset-button').on('click', function () {
    let placeholderImg = $("#placeholderImg").data('img');
    $('#viewer').attr('src', placeholderImg);
    $('#viewerBanner').attr('src', placeholderImg);
    $('#viewerBottomBanner').attr('src', placeholderImg);
    $('#viewerLogo').attr('src', placeholderImg);
    $('.spartan_remove_row').click();
})

$('#exampleInputPassword ,#exampleRepeatPassword').on('keyup', function () {
    let pass = $("#exampleInputPassword").val();
    let passRepeat = $("#exampleRepeatPassword").val();
    if (pass === passRepeat) {
        $('.pass').hide();
    } else {
        $('.pass').show();
    }
});

$('#apply').on('click', function () {
    let image = $("#image-set").val();
    if (image === null) {
        $('.image').show();
        return false;
    }
    let pass = $("#exampleInputPassword").val();
    let passRepeat = $("#exampleRepeatPassword").val();
    if (pass !== passRepeat) {
        $('.pass').show();
        return false;
    }
});

$("#add-vendor-form").on("submit",async function (event) {
    event.preventDefault();
    if (!await validateFormHelper($(this))) return false;

    let getText = $("#get-confirm-and-cancel-button-text");
    let targetUrl = $(this).data("redirect-route");

    Swal.fire({
        title: getText.data("sure"),
        text: $(this).data("message"),
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#1455AC",
        cancelButtonColor: "#d33",
        cancelButtonText: getText.data("cancel"),
        confirmButtonText: getText.data("confirm"),
        reverseButtons: true,
    }).then((result) => {
        if (result.value) {
            let formData = new FormData(document.getElementById('add-vendor-form'));
            $.ajaxSetup({
                headers: {
                    "X-XSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                        "content"
                    ),
                },
            });
            $.post({
                url: $(this).attr("action"),
                data: formData,
                contentType: false,
                processData: false,
                beforeSend: function () {
                    $("#loading").fadeIn();
                },
                success: function (data) {
                    if (data.errors) {
                        for (let index = 0; index < data.errors.length; index++) {
                            setTimeout(() => {
                                toastMagic.error(data.errors[index].message);
                            }, index * 500);
                        }
                    } else if (data.error) {
                        toastMagic.error(data.message);
                         setTimeout(() => {
                            if (targetUrl) {
                                location.href = targetUrl;
                            } else {
                                location.reload();
                            }
                        }, 2000)
                    } else {
                        toastMagic.success(data.message);
                        setTimeout(() => {
                            if (targetUrl) {
                                location.href = targetUrl;
                            } else {
                                location.reload();
                            }
                        }, 2000)
                    }
                },
                complete: function () {
                    $("#loading").fadeOut();
                    formSubmitCleanup($(this));
                }
            });
        }
    });
});
