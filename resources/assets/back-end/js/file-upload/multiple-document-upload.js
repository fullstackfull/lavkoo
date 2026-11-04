"use strict";

$(document).ready(function () {

    // File input change (delegated for dynamic content)
    $(document).on("change", ".document-upload-container .document_input", function(event) {
        const container = $(this).closest(".document-upload-container");
        const documentUploadWrapper = container.find(".doc-upload-wrapper");
        const fileAssets = container.find(".document-file-assets");
        const pictureIcon = fileAssets.data("picture-icon");
        const documentIcon = fileAssets.data("document-icon");
        const blankThumbnail = fileAssets.data("blank-thumbnail");

        let maxFileSizeInKiloBytes = parseFloat(this.dataset?.maxSize ?? 5) * 1024 * 1024;
        for (const file of Array.from(this.files)) {
            if (file.size > maxFileSizeInKiloBytes) {
                if (toastMagic?.error) {
                    toastMagic.error(this?.dataset?.validationErrorMsg ?? `File size is too large Maximum ${parseFloat(this.dataset?.maxSize ?? 5)} MB`);
                }
                this.value = '';
                return;
            }
        }

        // Keep uploaded files per container
        let uploadedFiles = container.data("uploadedFiles");
        if (!uploadedFiles) {
            uploadedFiles = new Map();
            container.data("uploadedFiles", uploadedFiles);
        }

        const input = this;
        const files = Array.from(input.files);
        const MAX_FILES = input.hasAttribute("multiple") ? 5 : 1;
        const currentFiles = container.find(".pdf-single").length;

        if (currentFiles + files.length > MAX_FILES) return;

        // Single file mode: remove old files
        if (!input.hasAttribute("multiple") && files.length > 0) {
            uploadedFiles.clear();
            container.find(".pdf-single").remove();
            documentUploadWrapper.hide();
            container.find(".after_upload_buttons").removeClass("d-none");
        }

        files.forEach(file => {
            if (!uploadedFiles.has(file.name)) {
                uploadedFiles.set(file.name, file);
                const fileURL = URL.createObjectURL(file);
                const fileType = file.type;
                const iconSrc = fileType.startsWith("image/") ? pictureIcon : documentIcon;

                const pdfSingle = $(`
                    <div class="pdf-single" data-file-name="${file.name}" data-file-url="${fileURL}">
                        <div class="pdf-frame">
                            <canvas class="pdf-preview d--none"></canvas>
                            <img class="pdf-thumbnail" src="${blankThumbnail}" alt="File Thumbnail">
                        </div>
                        <div class="overlay">
                            <a href="javascript:void(0);" class="remove-btn"><i class="bi bi-x-lg"></i></a>
                            <div class="pdf-info">
                                <img src="${iconSrc}" width="34" alt="File Type Logo">
                                <div class="file-name-wrapper">
                                    <span class="file-name">${file.name}</span>
                                    <span class="opacity-50">Click to view the file</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `);

                container.append(pdfSingle);
                container.find(".after_upload_buttons").removeClass("d-none");
                renderFileThumbnail(pdfSingle, fileType, blankThumbnail);
            }
        });

        documentUploadWrapper.toggle(container.find(".pdf-single").length < MAX_FILES);
    });

    // Edit button
    $(document).on("click", ".document-upload-container .doc_edit_btn", function() {
        const container = $(this).closest(".document-upload-container");
        const documentUploadWrapper = container.find(".doc-upload-wrapper");
        const uploadedFiles = container.data("uploadedFiles") || new Map();
        uploadedFiles.clear();
        container.data("uploadedFiles", uploadedFiles);
        container.find(".pdf-single").remove();
        documentUploadWrapper.show();
        container.find(".document_input").val("").click();
    });

    // Download button
    $(document).on("click", ".document-upload-container .doc_download_btn", function() {
        const container = $(this).closest(".document-upload-container");
        const pdfSingle = container.find(".pdf-single").first();
        const fileUrl = pdfSingle.data("file-url");
        const fileName = pdfSingle.data("file-name");
        if (!fileUrl || !fileName) return;
        const link = document.createElement("a");
        link.href = fileUrl;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });

    // Remove button (delegated)
    $(document).on("click", ".document-upload-container .remove-btn", function (event) {
        event.stopPropagation();
        const pdfSingle = $(this).closest(".pdf-single");
        const container = pdfSingle.closest(".document-upload-container");

        // Get the uploadedFiles map for this container
        let uploadedFiles = container.data("uploadedFiles");
        if (!uploadedFiles) {
            uploadedFiles = new Map();
            container.data("uploadedFiles", uploadedFiles);
        }

        const fileName = pdfSingle.data("file-name");

        // Remove from memory + UI
        uploadedFiles.delete(fileName);
        pdfSingle.remove();

        toggleUploadWrapper(container);
    });

    $(document).on("click", ".document-upload-container .pdf-single", function () {
        const fileURL = $(this).data("file-url");
        if (fileURL) {
            window.open(fileURL, "_blank");
        }
    });

    function toggleUploadWrapper(container) {
        const wrapper = container.find(".doc-upload-wrapper");
        const maxFiles = container.find(".document_input").attr("multiple") ? 5 : 1;
        const currentCount = container.find(".pdf-single").length;

        if (currentCount < maxFiles) {
            wrapper.show();
        } else {
            wrapper.hide();
        }
    }

    // Render file thumbnail
    async function renderFileThumbnail(element, fileType, blankThumbnail) {
        const fileUrl = element.data("file-url");
        const canvas = element.find(".pdf-preview")[0];
        const thumbnail = element.find(".pdf-thumbnail")[0];

        try {
            if (fileType.startsWith("image/")) {
                thumbnail.src = fileUrl;
            } else if (fileType === "application/pdf") {
                const ctx = canvas.getContext("2d");
                const pdf = await pdfjsLib.getDocument(fileUrl).promise;
                const page = await pdf.getPage(1);
                const viewport = page.getViewport({ scale: 0.5 });
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await page.render({ canvasContext: ctx, viewport }).promise;
                thumbnail.src = canvas.toDataURL();
            } else {
                thumbnail.src = blankThumbnail;
            }

            $(thumbnail).show();
            $(canvas).hide();
        } catch (error) {
            console.error("Error rendering file thumbnail:", error);
        }
    }


    try {
        // Submit form and include uploadedFiles
        $("form").on("submit", function (e) {
            const form = this;

            const formData = new FormData(form);

            $(this).find(".document-upload-container").each(function () {
                const container = $(this);
                const uploadedFiles = container.data("uploadedFiles");
                if (!uploadedFiles || uploadedFiles.size === 0) return;

                const input = container.find(".document_input")[0];
                const isArray = input.name.endsWith("[]");
                const fieldName = isArray ? input.name : input.name; // keep original name

                uploadedFiles.forEach((file, fileName) => {
                    formData.append(fieldName, file, fileName);
                });

                console.log("Files submitted from container:", Array.from(uploadedFiles.keys()));
            });
        });

        // Reset button per container
        $(document).on("click", "#reset_btn, #reset-btn", function () {
            const container = $(this).closest(".document-upload-container");
            const documentUploadWrapper = container.find(".doc-upload-wrapper");
            const uploadedFiles = container.data("uploadedFiles") || new Map();

            // Remove all previews
            container.find(".pdf-single").remove();
            uploadedFiles.clear();
            container.data("uploadedFiles", uploadedFiles);

            // Show the upload input
            documentUploadWrapper.show();
            container.find(".after_upload_buttons").addClass("d-none");
        });
    } catch (error) {
    }

    try {
        initializeDocumentUpload();
    } catch (error) {
        console.warn("Document upload initialization failed:", error);
    }
});

function initializeDocumentUpload() {

    $(document).find(".document-upload-container").each(function () {

        const container = $(this);
        const fileAssets = container.find(".document-file-assets");

        const pictureIcon = fileAssets.data("picture-icon");
        const documentIcon = fileAssets.data("document-icon");
        const blankThumbnail = fileAssets.data("blank-thumbnail");

        const existing = container.find(".document-existing-file");
        const url = existing.data("file-url");
        const name = existing.data("file-name");
        const type = existing.data("file-type");

        const wrapper = container.find(".document-upload-wrapper");
        const buttons = container.find(".after_upload_buttons");

        if (!url || !name) return; // No existing file

        const iconSrc = (type === "image") ? pictureIcon : documentIcon;

        const preview = $(`
            <div class="pdf-single mw-100" data-file-name="${name}" data-file-url="${url}">
                <div class="pdf-frame">
                    <canvas class="pdf-preview d--none"></canvas>
                    <img class="pdf-thumbnail" src="${blankThumbnail}">
                </div>
                <div class="overlay">
                    <div class="pdf-info">
                        <img src="${iconSrc}" width="34">
                        <div class="file-name-wrapper">
                            <span class="file-name">${name}</span>
                            <span class="opacity-50">Click to view the file</span>
                        </div>
                    </div>
                </div>
            </div>
        `);

        container.append(preview);

        // Render the thumbnail
        renderExistingFilePreview(preview, type, url, blankThumbnail);

        // Show buttons & hide uploader
        wrapper.hide();
        buttons.removeClass("d-none");
    });
}

function renderExistingFilePreview(preview, type, url, blankThumbnail) {
    const canvas = preview.find(".pdf-preview")[0];
    const thumbnail = preview.find(".pdf-thumbnail")[0];

    if (type === "image") {
        thumbnail.src = url;
        return;
    }

    if (type === "pdf" && window.pdfjsLib) {
        renderPDFThumbnail(preview, url);
        return;
    }

    thumbnail.src = blankThumbnail;
}

async function renderPDFThumbnail(preview, url) {
    const canvas = preview.find(".pdf-preview")[0];
    const thumbnail = preview.find(".pdf-thumbnail")[0];

    try {
        const ctx = canvas.getContext("2d");
        const pdf = await pdfjsLib.getDocument(url).promise;
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: 0.5 });

        canvas.width = viewport.width;
        canvas.height = viewport.height;

        await page.render({ canvasContext: ctx, viewport }).promise;
        thumbnail.src = canvas.toDataURL();
    } catch (error) {
        console.error("PDF Render Failed:", error);
        thumbnail.src = blankThumbnail;
    }
}
