"use strict";
function ajaxSetupToken() {
    $.ajaxSetup({
        headers: {
            "X-XSRF-TOKEN": $('meta[name="csrf-token"]').attr(
                "content"
            ),
        },
    });
}
$(document).on('submit', '#add-blog-category', function (e) {
    e.preventDefault();
    ajaxSetupToken()
    $.ajax({
        url: $(this).attr("action"),
        method: 'POST',
        data: $(this).serialize(),
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
                $('.category_name').val('')
                toastMagic.success(response.message);
                let tableBody = $('#categories-table');
                tableBody.empty();
                tableBody.append(response.html)
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});
$(document).on('submit', '#edit-blog-category', function (e) {
    e.preventDefault();
    ajaxSetupToken()
    $.ajax({
        url: $(this).attr("action"),
        method: 'POST',
        data: $(this).serialize(),
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
                $('.category_name').val('')
                toastMagic.success(response.message);
                let tableBody = $('#categories-table');
                tableBody.empty();
                tableBody.append(response.html)
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});
