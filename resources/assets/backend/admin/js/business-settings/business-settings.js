"use strict";

$('.color-code-preview').on('input', function() {
    const colorValue = $(this).val();
    $(this).siblings('.color-code-selection').text(colorValue);
});

$("#generateAndDownloadSitemap").on("click", function () {
    let getRoute = $(this).data("route");
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        type: "get",
        url: getRoute,
        beforeSend: function () {
            $("#generateAndDownloadSitemapSpinner").show();
            $(this).attr("disabled", true);
        },
        success: function (response) {
            siteMapDownloadFileUsingFileUrl(response.filePath);
            if (response.status === 1) {
                location.reload();
            }
        },
        error: function () {
            $("#generateAndDownloadSitemapSpinner").hide();
            $(this).attr("disabled", false);
        },
        complete: function () {
            $("#generateAndDownloadSitemapSpinner").hide();
            $(this).attr("disabled", false);
            location.reload();
        },
    });
});

function siteMapDownloadFileUsingFileUrl(url) {
    fetch(url)
        .then((response) => response.blob())
        .then((blob) => {
            const filename = url.substring(url.lastIndexOf("/") + 1);
            const blobUrl = window.URL.createObjectURL(new Blob([blob]));
            const link = document.createElement("a");
            link.href = blobUrl;
            link.setAttribute("download", filename);
            document.body.appendChild(link);
            link.click();
            link.parentNode.removeChild(link);
        })
        .catch((error) => console.warn("Error downloading file:", error));
}

$("#xml_file_input").on("change", function () {
    $("#xml-file-upload-placeholder").addClass("d-none");
    $("#xml_file_upload_progress").removeClass("d-none");
    setTimeout(() => {
        $("#xml_file_upload_submit").attr("disabled", false);
        xmlFileUploadProgressBar();
    }, 1000);
});

$("#xml_file_upload_cancel").on("click", function () {
    $("#xml_file_upload_submit").attr("disabled", true);
    $("#xml-file-upload-placeholder").removeClass("d-none");
    $("#xml_file_upload_progress").addClass("d-none");
    $("#xml_file_upload_form").trigger("reset");
    xmlFileUploadCancelProcess();
});

$(".xml_file_upload_close").on("click", function () {
    $("#xml_file_upload_submit").attr("disabled", true);
    $("#xml-file-upload-placeholder").removeClass("d-none");
    $("#xml_file_upload_progress").addClass("d-none");
    $("#xml_file_upload_form").trigger("reset");
    xmlFileUploadCancelProcess();
});

$("#xml_file_upload_progress .xml_file_upload_cancel_icon").on(
    "click",
    function () {
        xmlFileUploadCancelProcess();
    }
);

function xmlFileUploadProgressBar() {
    let initialValue = 0;
    const xmlProgressBar = setInterval(() => {
        let progressTextElement = $("#xml_file_upload_progress .progress-text");
        let progressBarElement = $("#xml_file_upload_progress .progress-bar");
        let progressText = "";
        if (initialValue < 100) {
            initialValue++;
            progressBarElement.attr("style", "width:" + initialValue + "%");
            progressText =
                initialValue + "% " + progressTextElement.data("progress");
            progressBarElement.removeClass("bg-success");
        } else {
            progressText =
                initialValue + "% " + progressTextElement.data("complete");
            clearInterval(xmlProgressBar);
            setTimeout(() => {
                progressBarElement.addClass("bg-success");
            }, 500);
        }
        progressTextElement.html(progressText);
    }, 5);
}

function xmlFileUploadCancelProcess() {
    let progressTextElement = $("#xml_file_upload_progress .progress-text");
    let progressBarElement = $("#xml_file_upload_progress .progress-bar");
    progressTextElement.html("0% " + progressTextElement.data("progress"));
    progressBarElement.attr("style", "width:0%");

    $("#xml_file_upload_submit").attr("disabled", true);
    $("#xml-file-upload-placeholder").removeClass("d-none");
    $("#xml_file_upload_progress").addClass("d-none");
    $("#xml_file_upload_form").trigger("reset");
}
