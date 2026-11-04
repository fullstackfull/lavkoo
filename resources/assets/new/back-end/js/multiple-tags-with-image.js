$(document).ready(function () {
    try {
        let vendorSelect = $(".multiple-tags-with-image .multiple-select2"); // Select2 dropdown
        let placeholderTypeShopNameText =
            $("#type-shop-name-text").data("text") ?? "Type shop name";

        // Initialize select2
        vendorSelect.select2({
            tags: true,
            maximumSelectionLength: false,
            placeholder: placeholderTypeShopNameText,
            templateResult: formatOption,
            templateSelection: formatOption,
            allowClear: false,
            closeOnSelect: false,
        });

        // Format dropdown options with image
        function formatOption(option) {
            if (!option.id) return option.text;
            const imgSrc = $(option.element).data("image");
            if (imgSrc) {
                return $(
                    `<span><img src="${imgSrc}" class="rounded-circle" style="width: 20px; height: 20px; margin-inline-end: 5px;"> ${option.text}</span>`
                );
            }
            return option.text;
        }

        // Add selected vendor to `selectedVendorObj`
        vendorSelect.on("select2:select", function (e) {
            const data = e.params.data;
            const imgSrc = $(this)
                .find(`option[value="${data.id}"]`)
                .data("image");

            // Prevent duplicates
            if (!selectedVendorObj.some((vendor) => vendor.id == data.id)) {
                selectedVendorObj.push({
                    id: data.id,
                    name: data.text,
                    img_src: imgSrc,
                });
            }
            renderSelectionOrderObjHtml(selectedVendorObj);
        });

        // Remove unselected vendor from `selectedVendorObj`
        vendorSelect.on("select2:unselect", function (e) {
            const data = e.params.data;
            selectedVendorObj = selectedVendorObj.filter(
                (vendor) => vendor.id != data.id
            );
            renderSelectionOrderObjHtml(selectedVendorObj);
        });

        $(document).on("click", ".show-tags .close-icon", function (e) {
            e.stopPropagation();
            var itemId = $(this).data("id");
            var selectedValues = vendorSelect.val();
            if (selectedValues) {
                selectedValues = selectedValues.filter((id) => id != itemId);
                vendorSelect.val(selectedValues).trigger("change");

                selectedVendorObj = selectedVendorObj.filter(
                    (vendor) => vendor.id != itemId
                );
                renderSelectionOrderObjHtml(selectedVendorObj);
            }
        });

        function renderSelectionOrderObjHtml(selectedVendorObj) {
            var htmlForSelectionOrderObj = "";
            selectedVendorObj.forEach(function (item) {
                htmlForSelectionOrderObj += `<li class="name d-flex gap-2">
                        <span><img class="rounded-circle tag-image-20px" src="${item.img_src}" alt="" /> ${item.name}</span>
                        <span class="close-icon d-flex h-100 align-items-center justify-content-center lh-1" data-id="${item.id}"><i class="fi fi-rr-cross-small cursor-pointer"></i></span>
                        <input value="${item.id}" name="vendor_priorities_id[]" class="d-none" />
                     </li>`;
            });
            $(".multiple-tags-with-image .show-tags").html(
                htmlForSelectionOrderObj
            );
        }
    } catch (e) {}
});
