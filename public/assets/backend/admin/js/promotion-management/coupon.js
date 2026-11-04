$(document).ready(function() {
    $("#coupon-add-ajax-submit").on("submit", function(e) {
        e.preventDefault();

        let $form = $(this);
        let formData = new FormData(this);

        $.ajaxSetup({
            headers: {"X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content")},
        });
        $.ajax({
            url: $form.attr("action"),
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.status === 1) {
                    toastMagic.success(response.message);
                    if (response?.redirect_url) {
                        setTimeout(function() {
                            window.location.href = response.redirect_url;
                        }, 2000);
                    }
                    $form.trigger("reset");
                } else {
                    toastMagic.warning(response.message);
                }
            },
            error: function(xhr) {
                console.warn(xhr.responseText);
                if (xhr.status === 422) {
                    let errors = xhr.responseJSON.errors;
                    let delay = 0;

                    $.each(errors, function(field, messages) {
                        $.each(messages, function(index, message) {
                            setTimeout(function() {
                                toastMagic.error(message);
                            }, delay);
                            delay += 700;
                        });
                    });

                }
                else {
                    toastMagic.error("Something went wrong!");
                }
            },
            complete: function() {
            }
        });
    });
});
