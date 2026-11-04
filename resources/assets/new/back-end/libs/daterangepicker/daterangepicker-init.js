"use strict";

$(document).ready(function() {
    // --- Date range Picker ---
    $(".js-daterangepicker").daterangepicker(
        {
            autoUpdateInput: false,
            drops: "auto",
            locale: {
                format: "DD MMM YYYY"
            }
        },
        function(start, end) {
            $("#reportrange span").html(
                start.format("DD MMM YYYY") + " - " + end.format("DD MMM YYYY")
            );
        }
    );

    $(".js-daterangepicker_till_current").daterangepicker({
        maxDate: moment(),
    });

    function updatePickerPosition() {
        $('.js-daterangepicker_till_current').each(function () {
            const picker = $(this).data('daterangepicker');

            if (picker && picker.isShowing) {
                picker.move();
            }
        });
    }

    $('#content')
        .on('scroll', updatePickerPosition)
        .on('touchmove', updatePickerPosition);

    $(".js-daterangepicker-times-sec").daterangepicker({
        timePicker: true,
        timePickerSeconds: true,
        timePicker24Hour: false,
        drops: "auto",
        locale: {
            format: "MM/DD/YYYY hh:mm:ss A"
        }
    });

    $(".js-daterangepicker-with-range").daterangepicker({
        timePicker: false,
        autoUpdateInput: false,
        drops: "auto",
        ranges: {
            Today: [moment(), moment()],
            Yesterday: [
                moment().subtract(1, "days"),
                moment().subtract(1, "days")
            ],
            "Last 7 Days": [moment().subtract(6, "days"), moment()],
            "Last 30 Days": [moment().subtract(29, "days"), moment()],
            "This Month": [moment().startOf("month"), moment().endOf("month")],
            "Last Month": [
                moment()
                    .subtract(1, "month")
                    .startOf("month"),
                moment()
                    .subtract(1, "month")
                    .endOf("month")
            ]
        },
        alwaysShowCalendars: true
    });

    $(".js-daterangepicker-with-range").on("apply.daterangepicker", function(
        ev,
        picker
    ) {
        $(this).removeAttr("readonly");
        $(this).removeClass("cursor-pointer");
        $(this).val(
            picker.startDate.format("MM/DD/YYYY") +
            " - " +
            picker.endDate.format("MM/DD/YYYY")
        );
    });

    $(".js-daterangepicker-time-only").daterangepicker(
        {
            timePicker: true,
            timePickerSeconds: true,
            timePicker24Hour: false,
            drops: "auto",
            locale: {
                format: "hh:mm:ss A"
            },
            opens: "center"
        },
        function(start, end) {
            updateTimeRange(start, end);
        }
    );
    $(".js-daterangepicker-time-only").on("show.daterangepicker", function() {
        const picker = $(this).data("daterangepicker");
        if (picker) {
            picker.container.find(".calendar-table").hide();
        }
    });
    function updateTimeRange(start, end) {
        $(".js-daterangepicker-time-only").val(
            start.format("hh:mm:ss A") + " - " + end.format("hh:mm:ss A")
        );
    }

// ---- single date daterangepicker
    $(".js-daterangepicker_single-date-with-placeholder").daterangepicker({
        singleDatePicker: true,
        autoUpdateInput: false,
        drops: "auto",
        locale: {
            cancelLabel: 'Clear'
        }
    });
    $(".js-daterangepicker_single-date-with-placeholder-add-new-vendor").daterangepicker({
        singleDatePicker: true,
        showDropdowns: true,
        minDate: moment(),
        autoUpdateInput: false,
        drops: "auto",
        locale: {
           cancelLabel: 'Clear'
        }
    });

    $(".js-daterangepicker_single-date-with-placeholder, .js-daterangepicker_single-date-with-placeholder-add-new-vendor").on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('MM/DD/YYYY'));
    });

    $(".js-daterangepicker_single-date-with-placeholder, .js-daterangepicker_single-date-with-placeholder-add-new-vendor").on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
    });

    /* Hide any open daterangepicker when the page (or any scrollable
       ancestor) scrolls. The picker is appended to <body> at fixed
       coordinates relative to the input; once the layout shifts, the
       picker floats in the wrong place and looks broken. The user's
       request: hide on scroll, click again to reopen. Capture-phase
       listener on window catches scroll events from any descendant
       container (page-level, .v2-main, modal bodies, etc.).

       MUST call the picker instance's own .hide() (not just $(el).hide())
       so the library's internal isShowing flag resets — otherwise the
       next click on the input is a no-op (library thinks it's already
       open) and the user can't reopen the calendar. */
    var dpSelectors = [
        '.js-daterangepicker',
        '.js-daterangepicker-times-sec',
        '.js-daterangepicker-with-range',
        '.js-daterangepicker-time-only',
        '.js-daterangepicker_single-date-with-placeholder',
        '.js-daterangepicker_single-date-with-placeholder-add-new-vendor'
    ].join(', ');

    window.addEventListener('scroll', function (e) {
        if ($(e.target).closest('.daterangepicker').length) return;
        if (!document.querySelector('.daterangepicker.show-calendar, .daterangepicker[style*="display: block"]')) return;
        // Reposition till-current pickers so they follow the input on scroll
        $('.js-daterangepicker_till_current').each(function () {
            var picker = $(this).data('daterangepicker');
            if (picker && picker.isShowing) picker.move();
        });
        // Hide all other picker types on scroll
        $(dpSelectors).each(function () {
            var picker = $(this).data('daterangepicker');
            if (picker && picker.isShowing) picker.hide();
        });
    }, true);
});
