'use strict';

$(document).ready(function () {

    let customTimeRangeStart = $('#custom_time_range_start');
    let customTimeRangeEnd = $('#custom_time_range_end');
    let dateErrorSection = $('#dateError');

    function updateVacationDatesBasedOnDuration(selectedOption, action = null) {
        if (selectedOption?.toString() === 'one_day' && action !== 'page_load') {
            let now = new Date();
            now.setSeconds(0, 0);
            let timezoneOffset = now.getTimezoneOffset() * 60000;
            let formattedNow = new Date(now.getTime() - timezoneOffset).toISOString().slice(0, 16);

            let end = new Date(now);
            end.setDate(end.getDate() + 1);
            end.setSeconds(0, 0);
            let formattedEnd = new Date(end.getTime() - timezoneOffset).toISOString().slice(0, 16);

            customTimeRangeStart.val(formattedNow).prop('readonly', false).prop('required', true);
            customTimeRangeEnd.val(formattedEnd).prop('readonly', false).prop('required', true);
            $('.custom-time-range-container').removeClass('opacity');
            $('.vacation-time-required').removeClass('d-none');
            dateErrorSection.hide();
        } else if (selectedOption?.toString() === 'until_change') {
            customTimeRangeStart.val('').prop('readonly', true).prop('required', false);
            customTimeRangeEnd.val('').prop('readonly', true).prop('required', false);
            $('.custom-time-range-container').addClass('opacity');
            $('.vacation-time-required').addClass('d-none');
            dateErrorSection.hide();
        } else if (selectedOption?.toString() === 'custom') {
            customTimeRangeStart.prop('readonly', false).prop('required', true);
            customTimeRangeEnd.prop('readonly', false).prop('required', true);
            $('.custom-time-range-container').removeClass('opacity');
            $('.vacation-time-required').removeClass('d-none');
            dateErrorSection.hide();
        }
    }

    function validateDates() {
        let start = new Date(customTimeRangeStart.val());
        let end = new Date(customTimeRangeEnd.val());
        if (start > end) {
            dateErrorSection.show();
            customTimeRangeStart.val('');
            customTimeRangeEnd.val('');
        } else {
            dateErrorSection.hide();
        }
    }

    let selectedVacationOption = $('input[name="vacation_duration_type"]:checked').val();
    updateVacationDatesBasedOnDuration(selectedVacationOption, 'page_load');

    $('#vendor-vacation-offcanvas-form [type="reset"]').on('click', function () {
        setTimeout(() => {
            updateVacationDatesBasedOnDuration(selectedVacationOption);
        }, 50)
    });

    $('input[name="vacation_duration_type"]').change(function () {
        updateVacationDatesBasedOnDuration($(this).val());
    });

    $('#custom_time_range_start, #custom_time_range_end').change(function () {
        let selectedOption = $('input[name="vacation_duration_type"]:checked').val();
        $('input[name="vacation_duration_type"][value="custom"]').prop('checked', true);

        if (selectedOption === 'custom') {
            customTimeRangeStart.prop('readonly', false).prop('required', true);
            customTimeRangeEnd.prop('readonly', false).prop('required', true);
        }

        if (selectedOption === 'one_day' && this.id === 'custom_time_range_start') {
            let start = new Date(customTimeRangeStart.val());
            if (!isNaN(start.getTime())) {
                start.setSeconds(0, 0);
                let end = new Date(start);
                end.setDate(end.getDate() + 1);
                end.setSeconds(0, 0);
                let timezoneOffset = end.getTimezoneOffset() * 60000;
                let formattedEnd = new Date(end.getTime() - timezoneOffset).toISOString().slice(0, 16);
                customTimeRangeEnd.val(formattedEnd);
            }
        }

        validateDates();
    });
    document.addEventListener("DOMContentLoaded", function () {
        const resetButton = document.querySelector('button[type="reset"]');
        resetButton.addEventListener("click", function (e) {
            document.getElementById("custom_time_range_start").value = "";
            document.getElementById("custom_time_range_end").value = "";
            document.getElementById("vacation_note").value = "";
            document.getElementById("duration_type3").checked = true;
            document.getElementById("vacation_close").checked = false;
        });
    });

    $("#tin-certificate-edit-btn").on("click", function () {
        $(".document_input").click();
    });
    $(".document_input").on('change', function () {
        $(".pdf-single").remove();
        $("#doc-upload-wrapper").show();
    });

    const originalValues = {};
    const fileModified = {};

    function captureOriginalValues() {
        $('form input, form select, form textarea').each(function () {
            const element = $(this);
            const name = element.attr('name');
            const type = element.attr('type');

            if (name && type !== 'file') {
                if (type === 'checkbox' || type === 'radio') {
                    originalValues[name] = element.is(':checked');
                } else {
                    originalValues[name] = element.val();
                }
            }
        });

        $('input[type="file"]').each(function () {
            const name = $(this).attr('name');
            if (name) {
                fileModified[name] = false;
            }
        });
    }

    $('input[type="file"]').on('change', function () {
        const name = $(this).attr('name');
        if (name) {
            fileModified[name] = true;
        }
    });

    $('#tin-certificate-edit-btn').on('click', function () {
        fileModified['tin_certificate'] = true;
    });

    function smartReset() {
        $('form input, form select, form textarea').each(function () {
            const element = $(this);
            const name = element.attr('name');
            const type = element.attr('type');

            if (name && type !== 'file' && originalValues.hasOwnProperty(name)) {
                if (type === 'checkbox' || type === 'radio') {
                    element.prop('checked', originalValues[name]);
                } else {
                    element.val(originalValues[name]);
                }
            }
        });

        $('input[type="file"]').each(function () {
            const element = $(this);
            const name = element.attr('name');

            if (name && fileModified[name]) {
                element.val('');
                fileModified[name] = false;
            }
        });
    }

    captureOriginalValues();

    $('#reset_btn').on('click', function (e) {
        e.preventDefault();
        smartReset();
    });
});


