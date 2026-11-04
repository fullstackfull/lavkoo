$(document).ready(function() {
    if ($(".upload-file").length) {
        initFileUpload();
        checkPreExistingImages();
    }
});

function initFileUpload() {
    $(document).on("change", ".single_file_input", function(e) {
        handleFileChange($(this), e.target.files[0]);
    });

    $(document).on("click", ".remove_btn", function() {
        resetFileUpload($(this).closest(".upload-file"));
    });

    $(document).on("click", ".edit_btn", function(e) {
        e.stopImmediatePropagation();
        let $card = $(this).closest(".upload-file");

        $card.removeClass("input-disabled");
        let $input = $card.find(".single_file_input");
        $input.trigger("click");
    });

    $(document).on("click", "button[type=reset]", function() {
        $(this)
            .closest("form")
            .find(".upload-file")
            .each(function() {
                resetFileUpload($(this));
            });
    });
}

function checkPreExistingImages() {
    $(".upload-file").each(function() {
        var $card = $(this);
        var $textbox = $card.find(".upload-file-textbox");
        var $imgElement = $card.find(".upload-file-img");
        var $removeBtn = $card.find(".remove_btn");
        let $overlay = $card.find(".overlay");

        // If there's already a valid image source
        if (
            $imgElement.attr("src") &&
            $imgElement.attr("src") !== window.location.href &&
            $imgElement.attr("src") !== ""
        ) {
            $textbox.hide();
            $imgElement.show();
            $overlay.addClass("show");
            $removeBtn.css("opacity", 1);
            $card.addClass("input-disabled");
        }
    });
}

function handleFileChange($input, file) {
    let $card = $input.closest(".upload-file");
    let $textbox = $card.find(".upload-file-textbox");
    let $imgElement = $card.find(".upload-file-img");
    let $removeBtn = $card.find(".remove_btn");
    let $overlay = $card.find(".overlay");
    $card.addClass("input-disabled");

    if (file) {
        let reader = new FileReader();
        reader.onload = function(e) {
            $textbox.hide();
            $imgElement.attr("src", e.target.result).show();
            $removeBtn.css("opacity", 1);
            $overlay.addClass("show");
        };
        reader.readAsDataURL(file);
    }
}

function resetFileUpload($card) {
    let $input = $card.find(".single_file_input");
    let $imgElement = $card.find(".upload-file-img");
    let $textbox = $card.find(".upload-file-textbox");
    let $removeBtn = $card.find(".remove_btn");
    let $overlay = $card.find(".overlay");
    let defaultSrc = $imgElement.data("default-src") || "";

    $input.val("");

    if (defaultSrc) {
        $imgElement.attr("src", defaultSrc).show();
        $textbox.hide();
        $overlay.addClass("show");
        $removeBtn.css("opacity", 1);
        $card.addClass("input-disabled");
    } else {
        $imgElement.hide().attr("src", "");
        $textbox.show();
        $overlay.removeClass("show");
        $removeBtn.css("opacity", 0);
        $card.removeClass("input-disabled");
    }
}
