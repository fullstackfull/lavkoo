"use strict";
$(document).ready(function () {
    let getDataTable = $("#get-data-table-route-and-text");
    let dataTablePageLength = [
        getDataTable.data("page-length"),
        10,
        20,
        50,
        100,
    ];
    dataTablePageLength.sort(function (a, b) {
        return a - b;
    });
    let uniquePageLengths = dataTablePageLength.filter(function (
        value,
        index,
        self
    ) {
        return self.indexOf(value) === index;
    });

    let dataTable = $("#dataTable").DataTable({
        pageLength: getDataTable.data("page-length"),
        lengthMenu: uniquePageLengths,
        ajax: {
            type: "get",
            url: getDataTable.data("route"),
            dataSrc: "",
        },
        language: {
            info: getDataTable.data("info"),
            infoEmpty: getDataTable.data("info-empty"),
            infoFiltered: getDataTable.data("info-filtered"),
            emptyTable: getDataTable.data("empty-table"),
            search: getDataTable.data("search"),
            lengthMenu: getDataTable.data("length-menu"),
            paginate: {
                first: '<i class="fi fi-rr-angle-left fs-10"></i>',
                last: '<i class="fi fi-rr-angle-right fs-10"></i>',
                next: '<i class="fi fi-rr-angle-right fs-10"></i>',
                previous: '<i class="fi fi-rr-angle-left fs-10"></i>',
            },
        },
        columns: [
            {
                data: null,
                className: "text-center",
                render: function (data, type, full, meta) {
                    return meta.row + 1;
                },
            },
            {
                className: "text-center overflow-hidden text-wrap max-w-200",
                data: "key",
            },
            {
                data: null,
                className: "text-center",
                render: function (data, type, full, meta) {
                    return (
                        `<textarea class="form-control w-100" id="value-${
                            meta.row + 1
                        }" value="` +
                        data.value +
                        `">${data.value}</textarea>`
                    );
                },
            },
            {
                data: null,
                className: "text-center",
                render: function (data, type, full, meta) {
                    return `<button type="button"  class="btn btn-outline-primary mx-auto icon-btn autoTranslate" data-key="${
                        data.encode
                    }" data-index="${meta.row + 1}">
                                        <i class="fi fi-sr-language-exchange"></i></button>`;
                },
            },
            {
                data: null,
                className: "text-center",
                render: function (data, type, full, meta) {
                    return `<button type="button" class="btn btn-primary mx-auto icon-btn update-lang" data-key="${
                        data.encode
                    }" data-index="${meta.row + 1}">
                                        <i class="fi fi-sr-disk"></i>
                                    </button>`;
                },
            },
        ],
        initComplete: function () {
            let dataTableWrapper = $("#dataTable_wrapper");
            let dataTablesLength = dataTableWrapper.find(".dt-length");
            let dataTablesInfo = dataTableWrapper.find(".dt-info");

            if (dataTablesLength.length && dataTablesInfo.length) {
                dataTablesLength.insertBefore(dataTablesInfo);
            }
            $(".dt-paging .first, .dt-paging .last").remove();
            $(".dt-length label")
                .contents()
                .filter(function () {
                    return this.nodeType === 3;
                })
                .remove();
            $("#dataTable_wrapper .dt-layout-row:has(input[type='search'])").addClass('d-none');
        },
        drawCallback: function () {
            $(".dt-paging .first, .dt-paging .last").remove();

            $(".dt-paging .previous").html(
                '<i class="fi fi-rr-angle-left fs-10"></i>'
            );
            $(".dt-paging .next").html(
                '<i class="fi fi-rr-angle-right fs-10"></i>'
            );
            // $("#dataTable_wrapper").find('.dt-layout-row').addClass('d-none');
        },
    });

    dataTable.on("draw.dt", function () {

    });

    dataTable.on("xhr.dt", function () {

    });

    dataTable.on("search.dt", function () {

    });

    function setProgress(percentage) {
        const circle = $('.progress-circle .progress');
        const radius = circle.attr('r');
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (percentage / 100 * circumference);

        circle.css('stroke-dashoffset', offset);
    }

    const timer = setTimeout(()=> {
        setProgress(87)
    }, 1000)
});


$(document).on("click", ".autoTranslate", function () {
    let currentElement = $(this);
    let autoTranslate = $("#get-auto-translate-route-and-text");

    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });

    $.ajax({
        url: autoTranslate.data("route"),
        method: "POST",
        data: {
            key: $(this).data("key"),
        },
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function (response) {
            toastMagic.success(autoTranslate.data("success-text"));
            $("#value-" + currentElement.data("index")).val(
                response.translated_data
            );
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

$(document).on("click", ".update-lang", function () {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });

    const translate = $("#get-translate-route-and-text");
    const value = $("#value-" + $(this).data("index")).val();

    $.ajax({
        url: translate.data("route"),
        method: "POST",
        data: {
            key: $(this).data("key"),
            value: value,
        },
        beforeSend: function () {
            $("#loading").fadeIn();
        },
        success: function () {
            toastMagic.success(translate.data("success-text"));
        },
        complete: function () {
            $("#loading").fadeOut();
        },
    });
});

let totalMessagesOfCurrentLanguageElement = $(
    "#total-messages-of-current-language"
);
var needToTranslateCall =
    parseInt(totalMessagesOfCurrentLanguageElement.data("total")) /
    totalMessagesOfCurrentLanguageElement.data("message-group");
var translateInitValue = 0;
var translateInitSpeedValue = 1000;
$("#translating-modal-start").on("click", function () {
    $(".translating-modal-success-rate").html("0%");
    $(".translating-modal-success-bar").attr("style", "width:0%");
    autoTranslationFunction();
});

function autoTranslationFunction() {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        url: $("#get-auto-translate-all-route-and-text").data("route"),
        method: "GET",
        beforeSend: function () {
            $("#translating-modal").modal("show");
        },
        success: function (response) {
            if (response.due_message != 0) {
                translateInitValue +=
                    Math.round((100 / needToTranslateCall) * 100) / 100;
                translateInitValue =
                    translateInitValue > 100 ? 100 : translateInitValue;
                $(".translating-modal-success-bar").attr(
                    "style",
                    "width:" + translateInitValue + "%"
                );
                $(".translating-modal-success-rate").html(
                    parseFloat(translateInitValue.toFixed(2)) + "%"
                );
                autoTranslationFunction();
            } else {
                toastMagic.success(response.message);
                $(".translateCountSuccess").html(
                    response.translate_success_message
                );
                translateInitSpeedValue = 10;
                translatingModalSuccessRate(translateInitSpeedValue);
                translateInitSpeedValue = 1000;
                setTimeout(() => {
                    $("#translating-modal").modal("hide");
                    setTimeout(() => {
                        $("#complete-modal").modal("show");
                    }, 500);
                }, 2000);
            }
        },
        complete: function () {},
        error: function (xhr, ajaxOption, thrownError) {},
    });
}

function translatingModalSuccessRate(SpeedValue) {
    const translatingRateInterval = setInterval(() => {
        if (translateInitValue < 100) {
            $(".translating-modal-success-rate").html(translateInitValue + "%");
            $(".translating-modal-success-bar").attr(
                "style",
                "width:" + translateInitValue + "%"
            );
            translateInitValue++;
        }
    }, SpeedValue);
    if (SpeedValue !== translateInitSpeedValue) {
        clearInterval(translatingRateInterval);
    }
}

$('#searchLanguage').on('input', function () {
    $('#dataTable').DataTable().search(this.value).draw();
});
