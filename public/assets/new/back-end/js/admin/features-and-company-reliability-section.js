"use strict";
function removeThisFeatureCard() {
    $(".remove-this-features-card").on("click", function() {
        const getText = $("#get-confirm-and-cancel-button-text");
        Swal.fire({
            title: getText.data("sure"),
            type: "warning",
            showCancelButton: true,
            confirmButtonColor: "#1455AC",
            cancelButtonColor: "#dd3333",
            cancelButtonText: getText.data("cancel"),
            confirmButtonText: getText.data("confirm"),
            reverseButtons: true
        }).then(result => {
            if (result.value) {
                $(this)
                    .closest(".remove-this-features-card-div")
                    .remove();
            }
        });
    });
}
removeThisFeatureCard();

$("#add-this-features-card-middle").on("click", function() {
    const getText = $("#get-feature-section-append-translate-text");
    let index = Math.floor(Math.random() * 100 + 1);
    let html = `<div class="col-sm-12 col-md-3 mb-4 remove-this-features-card-div">
                        <div class="card">
                            <div class="card-header justify-content-end">
                                <div class="cursor-pointer remove-this-features-card">
                                    <span class="btn btn-outline-danger icon-btn">
                                        <i class="fi fi-rr-trash"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="title" class="form-label text-capitalize">${getText.data(
                                        "title"
                                    )}</label>
                                    <input type="text" class="form-control" required
                                        name="features_section_middle[title][]"
                                        placeholder="${getText.data(
                                            "title-placeholder"
                                        )}">
                                </div>
                                <div class="mb-3">
                                    <label for="title" class="form-label text-capitalize">${getText.data(
                                        "sub-title"
                                    )}</label>
                                    <textarea class="form-control" name="features_section_middle[subtitle][]" required
                                        placeholder="${getText.data(
                                            "sub-title-placeholder"
                                        )}"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>`;

    $("#features-section-middle-row").append(html);
    removeThisFeatureCard();
});
$("#add-this-features-card-bottom").on("click", function() {
    let messageForOurCommitmentsFeatures = $(
        ".message-for-our-commitments-features"
    );
    let uploadIcon = $(".get-upload-icon").data("upload-icon");

    let index = Math.floor(Math.random() * 100 + 1);
    const getText = $("#get-feature-section-append-translate-text");
    let html = `<div class="col-sm-12 col-md-3 mb-4 remove-this-features-card-div">
                        <div class="card">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <h4 class="m-0 text-muted">${getText.data(
                                    "icon-box"
                                )}</h4>
                                <div class="cursor-pointer remove-this-features-card">
                                    <a class="btn btn-outline-danger icon-btn">
                                        <i class="fi fi-rr-trash"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="title" class="form-label text-capitalize">${getText.data(
                                        "title"
                                    )}</label>
                                    <input type="text" class="form-control" required
                                        name="features_section_bottom[title][]"
                                        placeholder="${getText.data(
                                            "title-placeholder"
                                        )}">
                                </div>
                                <div class="mb-3">
                                    <label for="title" class="form-label text-capitalize">${getText.data(
                                        "sub-title"
                                    )}</label>
                                    <textarea class="form-control" name="features_section_bottom[subtitle][]" required
                                        placeholder="${getText.data(
                                            "sub-title-placeholder"
                                        )}"></textarea>
                                </div>


                                <div class="m-auto upload-file__wrapper">
                                    <input type="file" name="features_section_bottom_icon[]" class="upload-color-image upload-file__input" id="" data-imgpreview="pre_img_header_logo${index}" accept=".jpg, .png, .jpeg, .gif, .bmp, .tif, .tiff|image/*">

                                    <span class="delete_file_input btn btn-outline-danger icon-btn d-none">
                                        <i class="fi fi-rr-trash"></i>
                                    </span>

                                    <div class="img_area_with_preview_new h-100 w-100 position-absolute z-2 p-0">
                                        <img id="pre_img_header_logo${index}" class="img-fit bg-white p-0" onerror="this.classList.add('d-none')" src="img" alt="">
                                    </div>
                                    <div class="position-absolute h-100 top-0 w-100 d-flex align-content-center justify-content-center">
                                        <div class="upload-file-textbox text-center d-flex flex-column justify-content-center align-items-center">
                                                <img width="34" height="34" class="svg" src="${uploadIcon}" alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">${messageForOurCommitmentsFeatures.data(
                                                        "click-to-upload"
                                                    )}</span>
                                                    <br>
                                                    ${messageForOurCommitmentsFeatures.data(
                                                        "drag-and-drop"
                                                    )}
                                                </h6>
                                            </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>`;

    $("#features-Section-bottom-row").append(html);
    removeThisFeatureCard();

    $(".upload-color-image").on("change", function() {
        uploadColorImage(this);
    });
    deleteInputFile();
    $(".delete_file_input").click(function() {
        let $parentDiv = $(this)
            .parent()
            .parent();
        $parentDiv.find('input[type="file"]').val("");
        $parentDiv
            .find(".img_area_with_preview_new h-100 w-100 img")
            .attr("src", " ");
        $(this).hide();
    });
    customUploadInputFile();
});
$(".remove_icon_box_with_titles").on("click", function() {
    const getText = $("#get-confirm-and-cancel-button-text");
    Swal.fire({
        title: getText.data("sure"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#1455AC",
        cancelButtonColor: "#d33",
        cancelButtonText: getText.data("cancel"),
        confirmButtonText: getText.data("confirm"),
        reverseButtons: true
    }).then(result => {
        if (result.value) {
            $.ajax({
                url: $("#get-feature-section-icon-remove-route").data("action"),
                method: "POST",
                data: {
                    _token: $('meta[name="_token"]').attr("content"),
                    title: $(this).data("title"),
                    subtitle: $(this).data("subtitle")
                },
                success: function(data) {
                    if (data.status === "success") {
                        location.reload();
                    }
                }
            });
        }
    });
});
$(".upload-color-image").on("change", function() {
    uploadColorImage(this);
});
function uploadColorImage(thisData = null) {
    if (thisData) {
        document
            .getElementById(thisData.dataset.imgpreview)
            .setAttribute("src", window.URL.createObjectURL(thisData.files[0]));
        document
            .getElementById(thisData.dataset.imgpreview)
            .classList.remove("d-none");
    }
}
function deleteInputFile() {
    $(".delete-file-input").click(function() {
        let $parentDiv = $(this)
            .parent()
            .parent();
        $parentDiv.find('input[type="file"]').val("");
        $parentDiv
            .find(".img_area_with_preview_new h-100 w-100 img")
            .attr("src", " ");
        $(this).hide();
    });
}
deleteInputFile();
function customUploadInputFile() {
    $(".custom-upload-input-file").on("change", function() {
        if (parseFloat($(this).prop("files").length) !== 0) {
            let $parentDiv = $(this).closest("div");
            $parentDiv.find(".delete-file-input").fadeIn();
        }
    });
}
customUploadInputFile();
