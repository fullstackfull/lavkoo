"use strict";

$('.theme-publish-status').on('click', function (event) {
    event.preventDefault();
})

$(".theme-publish-form").on("submit", function (event) {
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
            if (data.flag === "inactive") {
                $("#activateData").empty().html(data.view);
                $("#activatedThemeModal").addClass("bg-soft-dark").modal("show");
            } else {
                if (data.errors) {
                    for (let index = 0; index < data.errors.length; index++) {
                        setTimeout(() => {
                            toastMagic.error(data.errors[index].message);
                        }, index * 500);
                    }
                } else {
                    $('.theme-publish-status-' + $(this).find('[name="theme"]').val()).prop('checked', true);
                    toastMagic.success($("#get-success-text").data("success"));
                    if (parseInt(data.reload_action) === 1) {
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        $("#informationModalContent").empty().html(data.informationModal);
                        $("#InformationThemeModal").addClass("bg-soft-dark").modal("show");
                    }
                }
            }
            loadNotifyAllTheSellers()
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

$(".theme-delete-form").on("submit", function (event) {
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
            if (data.status === "success") {
                toastMagic.success(data.message);
                setTimeout(function () {
                    location.reload();
                }, 2000);

            } else if (data.status === "error") {
                toastMagic.error(data.message);
            }
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

function loadNotifyAllTheSellers() {
    $('.reload-by-onclick').on('click', function () {
        location.reload();
    });

    $('.notify-all-the-sellers').on('click', function () {
        $.ajaxSetup({
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
        });
        let notifyAllVendor = $("#get-notify-all-vendor-route-and-img-src");
        $.post({
            url: notifyAllVendor.data("action"),
            _token: notifyAllVendor.data("csrf"),
            beforeSend: function () {
                $("#loading").fadeIn();
            },
            success: function (data) {
                let message_html =
                    `<img src="${notifyAllVendor.data("src")}" alt="" width="50" class="mb-2">
                    <h5 class="mb-0 fs-14 ` + (data.status === 1 ? "text-success" : "text-danger") +`">
                        ${data.message}
                    </h5>`;

                $(".notify-all-the-sellers-area")
                    .empty()
                    .html(message_html)
                    .fadeIn();
                setTimeout(function () {
                    location.reload();
                }, 10000);
            },
            complete: function () {
                $("#loading").fadeOut();
            },
        });
    })
}
