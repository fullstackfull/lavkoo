"use strict";

let elementCustomUploadInputFileByID = $(".custom-upload-input-file");
let elementProductColorSwitcherByID = $("#product-color-switcher");
let elementImagePathOfProductUploadIconByID = $(
    "#image-path-of-product-upload-icon"
).data("path");
let messageUploadImage = $("#message-upload-image").data("text");
let messageFileSizeTooBig = $("#message-file-size-too-big").data("text");
let messagePleaseOnlyInputPNGOrJPG = $(
    "#message-please-only-input-png-or-jpg"
).data("text");


function addMoreImage(thisData, targetSection) {
    let $fileInputs = $(targetSection + " input[type='file']");
    let nonEmptyCount = 0;
    $fileInputs.each(function () {
        if (parseFloat($(this).prop("files").length) === 0) {
            nonEmptyCount++;
        }
    });

    uploadColorImage(thisData);

    if (nonEmptyCount === 0) {
        let datasetIndex = thisData.dataset.index + 1;

        let newHtmlData =
            `<div class="col-sm-12 col-md-4">
                        <div class="custom_upload_input position-relative border-dashed-2">
                            <input type="file" name="${thisData.name}" class="custom-upload-input-file action-add-more-image" data-index="${datasetIndex}" data-imgpreview="additional_Image_${datasetIndex}"
                                accept=".jpg, .webp, .png, .jpeg, .gif, .bmp, .tif, .tiff|image/*" data-target-section="${targetSection}">

                            <span class="delete_file_input delete_file_input_section btn btn-outline-danger btn-sm square-btn d-none">
                                <i class="fi fi-rr-trash"></i>
                            </span>

                            <div class="img_area_with_preview position-absolute z-index-2 border-0">
                                <img alt="" id="additional_Image_${datasetIndex}" class="h-auto aspect-1 bg-white d-none" src="img">
                            </div>
                            <div class="position-absolute h-100 top-0 w-100 d-flex align-content-center justify-content-center">
                                <div class="d-flex flex-column justify-content-center align-items-center">
                                    <img src="` +
            elementImagePathOfProductUploadIconByID +
            `" class="w-50" alt="">
                                    <h3 class="text-muted">` +
            messageUploadImage +
            `</h3>
                                </div>
                            </div>
                        </div>
                    </div>`;

        $(targetSection).append(newHtmlData);
    }

    elementCustomUploadInputFileByID.on("change", function () {
        if (parseFloat($(this).prop("files").length) !== 0) {
            let parentDiv = $(this).closest("div");
            parentDiv.find(".delete_file_input").fadeIn();
        }
    });

    $(".delete_file_input_section").click(function () {
        $(this).closest("div").parent().remove();
    });

    if (elementProductColorSwitcherByID.prop("checked")) {
        $("#additional_Image_Section .col-md-4").addClass("col-lg-2");
    } else {
        $("#additional_Image_Section .col-md-4").removeClass("col-lg-2");
    }

    $(".action-add-more-image").on("change", function () {
        let parentDiv = $(this).closest("div");
        parentDiv.find(".delete_file_input").removeClass("d-none");
        parentDiv.find(".delete_file_input").fadeIn();
        addMoreImage(this, $(this).data("target-section"));
    });

    $(".onerror-add-class-d-none").on("error", function () {
        $(this).addClass("d-none");
    });

    onErrorImage();
}


$(".delete_file_input").on("click", function () {
    let $parentDiv = $(this).parent().parent();
    $parentDiv.find('input[type="file"]').val("");
    $parentDiv.find(".img_area_with_preview img").addClass("d-none");
    $(this).removeClass("d-flex");
    $(this).hide();
});

function uploadColorImage(thisData = null) {
    if (thisData) {
        if (!thisData.files || !thisData.files[0]) {
            return;
        }
        const file = thisData.files[0];
        const acceptAttr = thisData.getAttribute("accept");
        if (acceptAttr) {
            const allowedExtensions = acceptAttr.split(',').map(ext => ext.trim().toLowerCase());
            const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(fileExtension)) {
                const errorMsg = thisData.dataset.validationErrorMsg || `Only allowed extensions are: ${allowedExtensions.join(', ')}`;
                toastMagic.error(errorMsg);
                thisData.value = '';
                const preview = document.getElementById(thisData.dataset.imgpreview);
                if (preview) {
                    preview.src = '';
                    preview.classList.add('d-none');
                }
                return;
            }
        }
        let maxFileSizeLimit = Number(thisData?.dataset?.maxSize || ($('#imageUploadMaxSize').data('max-size') || 20));
        let maxFileSizeLimitIAsByte = maxFileSizeLimit * 1024 * 1024;

        if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < thisData.files[0]?.size) {
            toastMagic.error(thisData.files[0]?.name + ` exceeds ${maxFileSizeLimit}MB`);
        } else {
            document.getElementById(thisData.dataset.imgpreview).setAttribute("src", window.URL.createObjectURL(thisData.files[0]));
            document.getElementById(thisData.dataset.imgpreview).classList.remove("d-none");

            try {
                if (
                    thisData.dataset.imgpreview == "pre_img_viewer" &&
                    !$("#meta_image_input").val()
                ) {
                    $("#pre_meta_image_viewer").removeClass("d-none");
                    $(".pre-meta-image-viewer").attr(
                        "src",
                        window.URL.createObjectURL(thisData.files[0])
                    ).addClass('d-block');
                }
            } catch (e) {
            }
        }

        if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < thisData.files[0]?.size) {
            try {
                document.getElementById(thisData.dataset.imgpreview).setAttribute("src", '');
                document.getElementById(thisData.dataset.imgpreview).classList.add("d-none");
                const uploadWrapper = thisData.closest(".upload-file");
                setTimeout(() => {
                    if (uploadWrapper) {
                        resetFileUploadAllImage(uploadWrapper);
                    }
                }, 200)
            } catch (e) {}
        }
    }
}


document.addEventListener('change', function (e) {
    if (e.target.matches('.action-upload-color-image')) {
        uploadColorImage(e.target);
    }
});

$(".delete_file_input").click(function () {
    let $parentDiv = $(this).closest("div");
    $parentDiv.find('input[type="file"]').val("");
    $parentDiv.find(".img_area_with_preview img").addClass("d-none");
    $(this).hide();
});

elementCustomUploadInputFileByID.on("change", function () {
    if (parseFloat($(this).prop("files").length) !== 0) {
        let $parentDiv = $(this).closest("div");
        $parentDiv.find(".delete_file_input").fadeIn();
    }
});

$(".image-uploader__zip").on("change", function (event) {
    const file = event.target.files[0];
    const target = $(this)
        .closest(".image-uploader")
        .find(".image-uploader__title");
    if (file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            target.text(file.name);
        };
        reader.readAsDataURL(file);
        $(".zip-remove-btn").show();
    } else {
        target.text("Upload File");
        $(".zip-remove-btn").hide();
    }
});
$(".image-uploader .zip-remove-btn").on("click", function (event) {
    $(this).closest(".image-uploader").find(".image-uploader__zip").val(null);
    $(this)
        .closest(".image-uploader")
        .find(".image-uploader__title")
        .text("Upload File");
    $(this).hide();
});

$.fn.select2DynamicDisplay = function () {
    function updateDisplay($element) {
        var $rendered = $element
            .siblings(".select2-container")
            .find(".select2-selection--multiple")
            .find(".select2-selection__rendered");
        var $container = $rendered.parent();
        var containerWidth = $container.width();
        var totalWidth = 0;
        var itemsToShow = [];
        var remainingCount = 0;

        // Get all selected items
        var selectedItems = $element.select2("data");

        // Create a temporary container to measure item widths
        var $tempContainer = $("<div>")
            .css({
                display: "inline-block",
                padding: "0 15px",
                "white-space": "nowrap",
                visibility: "hidden",
            })
            .appendTo($container);

        // Calculate the width of items and determine how many fit
        selectedItems.forEach(function (item) {
            var $tempItem = $("<span>")
                .text(item.text)
                .css({
                    display: "inline-block",
                    padding: "0 12px",
                    "white-space": "nowrap",
                })
                .appendTo($tempContainer);

            var itemWidth = $tempItem.outerWidth(true);

            if (totalWidth + itemWidth <= containerWidth - 40) {
                totalWidth += itemWidth;
                itemsToShow.push(item);
            } else {
                remainingCount = selectedItems.length - itemsToShow.length;
                return false;
            }
        });

        $tempContainer.remove();

        const $searchForm = $rendered.find(".select2-search");

        var html = "";
        itemsToShow.forEach(function (item) {
            html += `<li class="name">
                                    <span>${item.text}</span>
                                    <span class="close-icon" data-id="${item.id}"><i class="tio-clear"></i></span>
                                    </li>`;
        });
        if (remainingCount > 0) {
            html += `<li class="ms-auto">
                                    <div class="more">+${remainingCount}</div>
                                    </li>`;
        }
        html += $searchForm.prop("outerHTML");

        $rendered.html(html);

        function debounce(func, wait) {
            let timeout;
            return function (...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        // Attach event listener with debouncing
        $(".select2-search input").on(
            "input",
            debounce(function () {
                const inputValue = $(this).val().toLowerCase();

                const $listItems = $(".select2-results__options li");

                $listItems.each(function () {
                    const itemText = $(this).text().toLowerCase();
                    $(this).toggle(itemText.includes(inputValue));
                });
            }, 100)
        );

        $(".select2-search input").on("keydown", function (e) {
            if (e.which === 13) {
                e.preventDefault();

                const inputValue = $(this).val();
                if (
                    !inputValue ||
                    itemsToShow.find((item) => item.text === inputValue) ||
                    selectedItems.find((item) => item.text === inputValue)
                ) {
                    $(this).val("");
                    return null;
                }

                if (inputValue) {
                    $element.append(
                        new Option(inputValue, inputValue, true, true)
                    );
                    $element.val([...$element.val(), inputValue]);
                    $(this).val("");
                    $(".multiple-select2").select2DynamicDisplay();
                }
            }
        });
    }

    return this.each(function () {
        var $this = $(this);

        $this.select2({
            tags: true,
        });

        // Bind change event to update display
        $this.on("change", function () {
            updateDisplay($this);
        });

        // Initial display update
        updateDisplay($this);

        $(window).on("resize", function () {
            updateDisplay($this);
        });
        $(window).on("load", function () {
            updateDisplay($this);
        });

        // Handle the click event for the remove icon
        $(document).on(
            "click",
            ".select2-selection__rendered .close-icon",
            function (e) {
                e.stopPropagation();
                var $removeIcon = $(this);
                var itemId = $removeIcon.data("id");
                var $this2 = $removeIcon
                    .closest(".select2")
                    .siblings(".multiple-select2");
                $this2.val(
                    $this2.val().filter(function (id) {
                        return id != itemId;
                    })
                );
                $this2.trigger("change");
            }
        );
    });
};
$(".multiple-select2").select2DynamicDisplay();
