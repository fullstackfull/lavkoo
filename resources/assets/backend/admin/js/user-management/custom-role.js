'use strict';
$('#submit-create-role').on('submit', function (e) {
    let fields = $("input[name='modules[]']").serializeArray();
    let name = $("input[name='name']").val();
    if (name.trim() === '') {
        e.preventDefault();
        toastMagic.error($('#role-name-required-message').data('error'));
        return false;
    }
    if (fields.length === 0) {
        toastMagic.warning($('#select-minimum-one-box-message').data('warning'));
        return false;
    } else {
        $(this).submit();
    }
});
$("#select-all-module").on('change', function () {
    if ($(this).is(":checked") === true) {
        $(".module-permission").prop("checked", true);
    } else {
        $(".module-permission").prop("checked", false);
    }
});

$(document).ready(function () {
    checkboxSelectionCheck();

    $('.module-permission').on('click', function () {
        checkboxSelectionCheck();
    });
})

function checkboxSelectionCheck() {
    let nonEmptyCount = 0;
    $(".module-permission").each(function () {
        if ($(this).is(":checked") !== true) {
            nonEmptyCount++;
        }
    });

    if (nonEmptyCount === 0) {
        $("#select-all-module").prop("checked", true);
    } else {
        $("#select-all-module").prop("checked", false);
    }
}
