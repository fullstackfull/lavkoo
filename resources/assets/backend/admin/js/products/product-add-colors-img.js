"use strict";

let elementImagePathOfProductUploadIconByID = $("#image-path-of-product-upload-icon").data("path");

function colorWiseImageFunctionality(t) {
    let colors = t.val();
    let product_id = $('#product_id').val();
    let clickToUpload = $('#message-click-to-upload').data('text') ?? 'Click to upload';
    let dragAndDrop = $('#message-drag-and-drop').data('text') ?? 'Or drag and drop';
    let remove_url = $('#remove_url').val();
    let mergedColors = {};
    let colorObject = {};
    let colorImageObject = {};

    colors.forEach(function(item) {
        let clean = item.replace("#", "");
        colorObject[clean] = {'color' : clean, 'image_name' : {}};
    });

    let isCloneProductFromGallery = false;
    if ($("#clone-product-gallery").length) {
        isCloneProductFromGallery = true;
    }

    let colorImageJson = $("#color_image_json");
    if (colorImageJson.length) {
        let colorImageJsonValue = $('#color_image_json').val();
        colorImageJsonValue = colorImageJsonValue ? $.parseJSON(colorImageJsonValue) : [];
        $.each(colorImageJsonValue, function (index, item) {
            if (item.color) {
                colorImageObject[item.color] = item;
            }
        });
    }

    for (let color in colorObject) {
        mergedColors[color] = {
            ...colorObject[color],
            ...(colorImageObject[color] || {})
        };
    }

    $("#color-wise-image-section").empty().html("");
    $.each(mergedColors, function (key, colorItem) {

        let color = "color_image_" + key;
        let colorCode = "#" + key;

        let productGalleryPage = $('#product-gallery-page')?.data('page')?.toString() === 'product-gallery';

        let imagePath = "";
        if (colorItem?.image_name && colorItem?.image_name?.path) {
            imagePath = colorItem?.image_name?.path;
        }
        let maxFileSizeLimit = Number($('#imageUploadMaxSize').data('max-size') || 20);
        let generateHtml =
            `<div><div class="upload-file position-relative">
                <label for="color-img-upload-${key}">
                    <input type="file" name="${color}" class="single_file_input upload-file__input action-upload-color-image"
                    id="color-img-upload-${key}" data-index="1" data-imgpreview="additional_Image_${key}"
                     data-validation-error-msg="Only allowed extensions are: .jpg, .jpeg, .png, .webp"
                    accept=".jpg, .webp, .png, .jpeg" data-max-size="${maxFileSizeLimit}" required>
                </label>

                <div class="position-absolute start-0 z-10">
                    <label for="color-img-upload-${key}" class="p-1">
                            <div class="btn-circle shadow-lg" style="--size: 22px; background-color: ${colorCode}; border: 1.5px solid #fff;"></div>
                    </label>
                </div>

                <div class="position-absolute end-0 d-flex gap-2 z-10 p-1">
                    <label for="color-img-upload-${key}" class="btn btn-outline-info bg-white icon-btn position-relative fs-10 product-image-edit-icon ${imagePath ? '' : 'd-none'}" style="--size: 24px;">
                        <i class="fi fi-sr-pencil"></i>
                    </label>
                    `+
                    ( colorItem?.image_name?.key && productGalleryPage === false ?
                        `<a href="` + remove_url + `?id=${product_id}&name=${colorItem?.image_name?.key}&color=${key}"
                           class="btn btn-danger cursor-pointer icon-btn fs-10" style="--size: 24px;"><i class="fi fi-rr-trash"></i></a>` : ``
                    )
                    + `
                </div>
                <div class="upload-file__wrapper">
                    <img id="additional_Image_${key}" alt="" class="upload-file-img ${imagePath ? 'd-block' : '' }"
                        src="${imagePath ?? 'img' }">
                    `+
                        (isCloneProductFromGallery && colorItem?.image_name?.key ?
                        `<input type="text" name="color_image_${key}[]" value="${colorItem?.image_name?.key}" hidden>` : ``)
                    +`
                    <div class="upload-file-textbox text-center ${imagePath ? 'd-none' : '' }">
                        <img width="34" height="34" class="svg" src="${$("#image-path-of-product-upload-icon").data("path")}"
                        alt="image upload">
                        <h6 class="mt-1 fw-medium lh-base text-center">
                            <span class="text-info">${clickToUpload}</span>
                            <br>${dragAndDrop}
                        </h6>
                    </div>
                </div>
                </div></div></div>`;
        $("#color-wise-image-section").append(generateHtml);
        uploadColorImage();
    })

    $(".action-upload-color-image").on("change", function () {
        uploadColorImage(this);
    });

    $(".onerror-add-class-d-none").on("error", function () {
        $(this).addClass("d-none");
    });
}

$(".action-upload-color-image").on("change", function () {
    uploadColorImage(this);
});

function uploadColorImage(thisData = null) {
    if (thisData) {
        const acceptAttr = thisData.getAttribute("accept");
        const file = thisData.files[0];
        if (acceptAttr) {
            const allowedExtensions = acceptAttr.split(',').map(ext => ext.trim().toLowerCase());
            const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(fileExtension)) {
                const errorMsg = thisData.dataset.validationErrorMsg || `Only allowed extensions are: ${allowedExtensions.join(', ')}`;
                toastMagic.error(errorMsg);
                thisData.value = '';
                return;
            }
        }
        let maxFileSizeLimit = Number(thisData?.dataset?.maxSize || ($('#imageUploadMaxSize').data('max-size') || 20));
        let maxFileSizeLimitIAsByte = maxFileSizeLimit * 1024 * 1024;

        if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < thisData.files[0]?.size) {
            toastMagic.error(thisData.files[0]?.name + ` exceeds ${maxFileSizeLimit}MB`);
        } else {
            setTimeout(() => {
                const previewImg = document.getElementById(thisData.dataset.imgpreview);
                if (previewImg) {
                    previewImg.setAttribute("src", window.URL.createObjectURL(thisData.files[0]));
                    previewImg.classList.remove("d-none");

                     $(thisData)
                        .closest(".upload-file")
                        .find(".product-image-edit-icon")
                        .removeClass("d-none");
                }

                try {
                    if (thisData.dataset.imgpreview?.toString() === "pre_img_viewer" && !$("#meta_image_input").val()) {
                        $("#pre_meta_image_viewer").removeClass("d-none");
                        $(".pre-meta-image-viewer")
                            .attr("src", window.URL.createObjectURL(thisData.files[0]))
                            .addClass('d-block')
                            .closest('label').find('.upload-file-textbox').addClass('d-none');
                    }
                } catch (e) {

                }
            }, 100)
        }

        if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < thisData.files[0]?.size) {
            try {
                const previewImg = document.getElementById(thisData.dataset.imgpreview);
                if (previewImg) {
                    previewImg.setAttribute("src", '');
                    previewImg.classList.add("d-none");
                }
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
