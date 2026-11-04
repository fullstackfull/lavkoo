'use strict';

$(document).ready(function () {

    let customTimeRangeStart = $('#custom_time_range_start');
    let customTimeRangeEnd = $('#custom_time_range_end');
    let dateErrorSection = $('#dateError');

    function updateVacationDatesBasedOnDuration(selectedOption, action = null) {
        if (selectedOption === 'one_day' && action !== 'page_load') {
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
        } else if (selectedOption === 'until_change') {
            customTimeRangeStart.val('').prop('readonly', true).prop('required', false);
            customTimeRangeEnd.val('').prop('readonly', true).prop('required', false);
            $('.custom-time-range-container').addClass('opacity');
            $('.vacation-time-required').addClass('d-none');
            dateErrorSection.hide();
        } else if (selectedOption === 'custom') {
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



    $('#reset_btn').on('click', function (e) {
        e.preventDefault();
        const form = this.closest('form');


        Array.from(form.elements).forEach(input => {
            if (input.type !== 'file') {
                input.value = '';
            }
        });

        const fileInput = form.querySelector('input[name="tin_certificate"]');
        const pdfSingle = form.querySelector('.pdf-single');
        const defaultDocThumb = pdfSingle?.getAttribute('data-default-src');

        if (fileInput && fileInput.value) {
            fileInput.value = '';

            const previewImg = pdfSingle.querySelector('.pdf-thumbnail');
            if (previewImg) {
                previewImg.src = defaultDocThumb;
            }

            pdfSingle.style.display = 'block';

            const uploadWrapper = form.querySelector('#doc-upload-wrapper');
            if (uploadWrapper) {
                uploadWrapper.style.display = 'none';
            }
        }
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
});


