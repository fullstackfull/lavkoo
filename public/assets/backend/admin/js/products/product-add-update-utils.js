"use strict";

function generateRandomString(length) {
    let result = "";
    let characters = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    let charactersLength = characters.length;
    for (let i = 0; i < length; i++) {
        result += characters.charAt(
            Math.floor(Math.random() * charactersLength)
        );
    }
    return result;
}

document.addEventListener("keyup", function(e) {
    if (e.target.id === "generate-sku-code") {
        generateSKUPlaceHolder();
    }
});

$("#product_type").on("change", function() {
    getProductTypeFunctionality();
});

$("#digital-product-type-input").on("change", function() {
    getUpdateDigitalVariationFunctionality();
});

$("#product-color-switcher").on("click", function() {
    elementProductColorSwitcherByIDFunctionality();
    colorWiseImageFunctionality($("#colors-selector-input"));
});

document.addEventListener("click", function(e) {
    if (e.target.classList.contains("action-onclick-generate-number")) {
        let getElement = e.target.dataset.input;
        document.querySelector(getElement).value = generateRandomString(6);
        generateSKUPlaceHolder();
    }
});

document.addEventListener("change", function(e) {
    if (e.target.classList.contains("action-add-more-image")) {
        let parentDiv = e.target.closest("div");
        let deleteFileInput = parentDiv.querySelector(".delete_file_input");

        if (deleteFileInput) {
            deleteFileInput.classList.remove("d-none");
            deleteFileInput.style.display = "block";
        }
        addMoreImage(e.target, e.target.dataset.targetSection);
    }
});

document.addEventListener("change", function(e) {
    if (e.target.classList.contains("action-get-request-onchange")) {
        let getUrlPrefix = e.target.dataset.urlPrefix + e.target.value;
        let id = e.target.dataset.elementId;
        let getElementType = e.target.dataset.elementType;
        getRequestFunctionality(getUrlPrefix, id, getElementType);
    }
});

$("#product-choice-attributes").on("change", function() {
    $("#sku_combination")
        .empty()
        .html("");
    $("#customer-choice-options-container")
        .empty()
        .html("");
    $.each($("#product-choice-attributes option:selected"), function() {
        addMoreCustomerChoiceOption($(this).val(), $(this).text());
    });
    getUpdateSKUFunctionality();
});

function generateSKUPlaceHolder() {
    let exampleText = document.getElementById("get-example-text").dataset
        .example;
    let codeValue = document.querySelector("input[name='code']").value;
    let newPlaceholderValue = `${exampleText} : ${codeValue}-MCU-47-V593-M`;

    document.querySelectorAll(".store-keeping-unit").forEach(function(element) {
        element.setAttribute("placeholder", newPlaceholderValue);
    });
}

document
    .querySelectorAll('input[name="colors_active"]')
    .forEach(function(input) {
        input.addEventListener("change", function() {
            const isChecked = Array.from(
                document.querySelectorAll('input[name="colors_active"]')
            ).some(el => el.checked);
            document.getElementById(
                "colors-selector-input"
            ).disabled = !isChecked;
        });
    });

$(document).on('change', 'input[name^="choice_options_"]', function() {
    getUpdateSKUFunctionality();
});

function addMoreCustomerChoiceOption(index, name) {
    let nameSplit = name.split(" ").join("");
    let genHtml = `<div class="col-md-6"><div class="form-group mb-0">
                <input type="hidden" name="choice_no[]" value="${index}">
                    <label class="form-label">${nameSplit}</label>
                    <input type="text" name="choice[]" value="${nameSplit}" hidden>
                    <div class="">
                        <input type="text" class="form-control alpha-numeric-input" name="choice_options_${index}[]"
                        placeholder="${$("#message-enter-choice-values").data("text")}"
                        data-role="tagsinput">
                    </div>
                </div>
        </div>`;

    document.getElementById("customer-choice-options-container")
        .insertAdjacentHTML("beforeend", genHtml);

    document.querySelectorAll("input[data-role=tagsinput], select[multiple][data-role=tagsinput]")
        .forEach(function(input) {
            $(input).tagsinput();
        });
}

document
    .querySelector('input[name="unit_price"]')
    .addEventListener("keyup", function() {
        let productType = document.getElementById("product_type").value;
        if (productType && productType.toString() === "physical") {
            getUpdateSKUFunctionality();
        }
        getUpdateDigitalVariationFunctionality();

        setTimeout(function() {
            document
                .querySelectorAll(".variation-price-input")
                .forEach(function(element) {
                    element.value = this.value;
                });
        }, 500);
    });

$("#colors-selector-input").on("change", function() {
    let elementProductColorSwitcherByID = $("#product-color-switcher");
    if (
        elementProductColorSwitcherByID &&
        elementProductColorSwitcherByID.prop("checked")
    ) {
        colorWiseImageFunctionality($("#colors-selector-input"));
        $("#color-wise-image-area").show();
    } else {
        $("#color-wise-image-area").hide();
    }
    getUpdateSKUFunctionality();

    try {
        initFileUpload();
    } catch (e) {
        console.warn(e);
    }
});

document.querySelectorAll(".product-discount-type").forEach(function(select) {
    select.addEventListener("change", function() {
        const symbolElement = document.querySelector(".discount-amount-symbol");
        const currency = symbolElement.dataset.currency;
        const percent = symbolElement.dataset.percent;

        if (this.value.toString() === "flat") {
            symbolElement.innerHTML = `(${currency})`;
        } else {
            symbolElement.innerHTML = `(${percent})`;
        }
    });
});

function getProductTypeFunctionality() {
    let productType = $("#product_type").val();
    if (productType && productType.toString() === "physical") {
        elementProductColorSwitcherByIDFunctionality("reset");
        $(".show-for-physical-product").show();
        $(".show-for-digital-product").hide();

        $("#digital_file_ready").val("");
    } else if (productType && productType.toString() === "digital") {
        elementProductColorSwitcherByIDFunctionality("reset");
        $(".show-for-physical-product").hide();
        $(".show-for-digital-product").show();
        $(".color_image_column").addClass("d-none");

        $("#product-color-switcher").prop("checked", false);
        $("#color-wise-image-section")
            .empty()
            .html("");
    }

    try {
        if (productType && productType.toString() === "physical") {
            $("#digital-product-variation-section").empty().html();
            $("#digital-product-single-file-upload-section").empty().html();
            $(
                "#digital-product-type-choice-section .extension-choice-section"
            ).remove();
        }
    } catch (e) {}
}

function ProductVariationFileUploadFunctionality() {
    document
        .querySelectorAll('.variation-upload-item input[type="file"]')
        .forEach(function(input) {
            input.addEventListener("change", function() {
                const $form = $(this).closest("form.custom-validation");
                if ($form.length && !$form.validate().element(this)) {
                    this.value = "";
                    return;
                }
                const file = this.files[0];

                let maxFileSizeLimit = Number(this?.dataset?.maxSize || ($('#imageUploadMaxSize').data('max-size') || 20));
                let maxFileSizeLimitIAsByte = maxFileSizeLimit * 1024 * 1024;

                if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < this.files[0]?.size) {
                    toastMagic.error(this.files[0]?.name + ` exceeds ${maxFileSizeLimit}MB`);
                } else {
                    if (file) {
                        let variationUploadItem = this.closest(
                            ".variation-upload-item"
                        );
                        variationUploadItem
                            .querySelector(".variation-upload-file")
                            .classList.add("collapse");
                        variationUploadItem
                            .querySelector(".uploading-item")
                            .classList.remove("collapse");

                        const timer = setTimeout(() => {
                            variationUploadItem
                                .querySelector(".uploading-item")
                                .classList.add("collapse");
                            variationUploadItem
                                .querySelector(".uploaded-item")
                                .classList.remove("collapse");
                            variationUploadItem.querySelector(
                                ".uploaded-item .file-name"
                            ).textContent = file.name;
                        }, 500);

                        return () => clearTimeout(timer);
                    }
                }
            });
        });

    document.querySelectorAll(".cancel-upload").forEach(function(button) {
        button.addEventListener("click", function() {
            let variationUploadItem = this.closest(".variation-upload-item");
            variationUploadItem
                .querySelector(".variation-upload-file")
                .classList.remove("collapse");
            variationUploadItem
                .querySelector(".uploading-item")
                .classList.add("collapse");
            variationUploadItem
                .querySelector(".uploaded-item")
                .classList.add("collapse");
            variationUploadItem.querySelector('input[type="file"]').value = "";
        });
    });
}

$("#digital-product-type-select").on("change", function() {
    $(
        "#digital-product-type-choice-section .extension-choice-section"
    ).remove();
    $("#digital-product-variation-section").empty().html();
    $("#digital-product-single-file-upload-section").empty().html();
    $.each($("#digital-product-type-select option:selected"), function() {
        addMoreDigitalProductChoiceOption($(this).val(), $(this).text());
    });
    getUpdateDigitalVariationFunctionality();
});

function addMoreDigitalProductChoiceOption(index, name) {
    let nameSplit = name.split(" ").join("");
    let ExtensionText = $("#get-extension-text-message").data("text");
    let genHtml =
        `<div class="col-lg-6 extension-choice-section">
                <div class="form-group mb-0">
                    <input type="hidden" name="extensions_type[]" value="${index}">
                    <label class="form-label">${nameSplit} ${ExtensionText}</label>
                    <input type="text" name="extensions[]" value="${nameSplit}" hidden>
                    <div class="">
                        <input type="text" class="form-control" name="extensions_options_${index}[]"
                        placeholder="` +
        $("#message-enter-choice-values").data("text") +
        `" data-role="tagsinput" onchange="getUpdateDigitalVariationFunctionality()">
                    </div>
                </div>
        </div>`;
    $("#digital-product-type-choice-section").append(genHtml);
    $(
        "input[data-role=tagsinput], select[multiple][data-role=tagsinput]"
    ).tagsinput();
}

$(".product-title-default-language").on("change keyup keypress", function() {
    $("#meta_title").val($(this).val());
    getUpdateDigitalVariationFunctionality();
    getUpdateSKUFunctionality();
});
