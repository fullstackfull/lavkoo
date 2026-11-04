'use strict';

$(".get-checked-required-field").on("submit", function (e) {
    let isValid = true;

    $(this).find("[required]").each(function () {
        let fieldType = $(this).attr("type");
        if (fieldType === "checkbox" || fieldType === "radio") {
            if ($("[name='" + $(this).attr("name") + "']:checked").length === 0) {
                isValid = false;
            }
        } else if (fieldType === "file") {
            if (!$(this).val()) {
                isValid = false;
            }
        } else {
            if (!$(this).val() || $(this).val() === "") {
                isValid = false;
            }
        }
        if (!isValid) {
            let msgContainer = $('.msg-checked-required-field');
            toastMagic.error(msgContainer.data('title'), msgContainer.data('description'), true);
            e.preventDefault();
            return false;
        }
    });
});
