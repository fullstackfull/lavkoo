class FileUpload {
    constructor(inputElement) {
        this.input = inputElement;
        this.files = [];
        this.maxSize = this.parseMaxFileSize(
            $(inputElement).data("max-file-size")
        );
        this.isHandlingChange = false;
        this.initialize();
    }

    parseMaxFileSize(sizeString) {
        if (!sizeString) return null;

        const sizeMatch = sizeString.match(/^(\d+)(MB|KB|GB)?$/i);
        if (!sizeMatch) return null;

        const size = parseInt(sizeMatch[1]);
        const unit = (sizeMatch[2] || "MB").toUpperCase();

        switch (unit) {
            case "KB":
                return size * 1024;
            case "MB":
                return size * 1024 * 1024;
            case "GB":
                return size * 1024 * 1024 * 1024;
            default:
                return size * 1024 * 1024;
        }
    }

    initialize() {
        if (this.input.files && this.input.files.length > 0) {
            this.files = Array.from(this.input.files);
        }
    }

    addFiles(newFiles) {
        const isMultiple = this.input.multiple;
        const newFilesArray = Array.from(newFiles);

        const totalNewSize = newFilesArray.reduce(
            (acc, file) => acc + file.size,
            0
        );
        const existingSize = this.files.reduce(
            (acc, file) => acc + file.size,
            0
        );
        const combinedSize = totalNewSize + existingSize;

        if (this.maxSize && combinedSize > this.maxSize) {
            const maxSizeMB = (this.maxSize / (1024 * 1024)).toFixed(0);
            toastMagic.error(
                `Total file size exceeds maximum limit (${maxSizeMB}MB)`
            );
            this.updateInput();
            return;
        }

        let validFiles = newFilesArray;
        if (isMultiple) {
            const existingKeys = new Set(
                this.files.map((f) => `${f.name}-${f.size}`)
            );
            validFiles = newFilesArray.filter(
                (file) => !existingKeys.has(`${file.name}-${file.size}`)
            );
            this.files = [...this.files, ...validFiles];
        } else {
            this.files = validFiles.slice(0, 1);
        }

        this.updateInput();
    }

    removeFile(index) {
        if (index >= 0 && index < this.files.length) {
            this.files.splice(index, 1);
            this.updateInput();
        }
    }

    updateInput() {
        const dataTransfer = new DataTransfer();
        this.files.forEach((file) => {
            dataTransfer.items.add(file);
        });

        $(this.input).off("change");
        this.input.files = dataTransfer.files;

        setTimeout(() => {
            $(this.input).on("change", this.handleChange.bind(this));
        }, 100);

        this.clearError();
        renderFilePreview($(this.input).closest(".custom-file-upload"));
    }

    handleChange() {
        if (this.isHandlingChange) return;

        this.isHandlingChange = true;
        try {
            const selectedFiles = Array.from(this.input.files);

            if (selectedFiles.length === 0) {
                this.updateInput();
                return;
            }

            this.addFiles(selectedFiles);
        } finally {
            this.isHandlingChange = false;
        }
    }

    showError(message) {
        const container = $(this.input).closest(".custom-file-upload");
        let errorElement = container.find(".file-upload-error");

        if (errorElement.length === 0) {
            errorElement = $(
                '<div class="file-upload-error alert alert-danger"></div>'
            );
            container.append(errorElement);
        }

        errorElement.html(message).show();
    }

    clearError() {
        $(this.input)
            .closest(".custom-file-upload")
            .find(".file-upload-error")
            .hide();
    }

    getFileDetails() {
        return this.files.map((file) => ({
            name: file.name,
            sizeMB: (file.size / (1024 * 1024)).toFixed(2),
            type: file.type,
            size: file.size,
        }));
    }
}

function initializeFileUploads() {
    $('.custom-file-upload input[type="file"]').each(function () {
        const input = this;

        if ($(input).data("uploader-initialized")) return;

        const uploader = new FileUpload(input);
        $(input).data("uploader", uploader);
        $(input).data("uploader-initialized", true);

        $(input).on("change", uploader.handleChange.bind(uploader));

        // Native form reset doesn't fire a `change` on file inputs, so the uploader's
        // internal file list and the preview UI go stale after clicking type="reset".
        const form = input.form;
        if (form) {
            form.addEventListener("reset", function () {
                setTimeout(function () {
                    uploader.files = [];
                    uploader.updateInput();
                }, 0);
            });
        }
    });
}

function renderFilePreview(container) {
    const input = container.find('input[type="file"]')[0];
    const uploader = $(input).data("uploader");
    const previewContainer = container
        .closest(".file-upload-parent")
        .find(".file-preview-list");
    const fileIconSrc =
        container
            .closest(".file-upload-parent")
            .find("#file-upload-config")
            ?.data("icon-src") || "";
    const maxSize = uploader?.maxSize;

    previewContainer.empty();

    if (!uploader || uploader.files.length === 0) {
        console.info("No files to preview");
        return;
    }

    uploader.getFileDetails().forEach((file, index) => {
        const sizeWarning = maxSize && file.size > maxSize ? "text-danger" : "";

        const fileElement = $(`
             <div class="file-preview-list_single bg-white rounded-10 d-flex align-items-center justify-content-between gap-3 p-3" data-index="${index}">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <img width="24" class="aspect-1 flex-shrink-0" src="${fileIconSrc}" alt="">
                    <span class="fs-12 line-1 file_title">${file.name}</span>
                </div>
                <h5 class="mb-0 file_size">${file.sizeMB}MB</h5>
                <button type="button" class="btn btn-danger btn-circle close_btn">
                    <i class="fi fi-sr-cross-small fs-16"></i>
                </button>
            </div>
        `);

        previewContainer.append(fileElement);
    });

    $(document).on("click", ".close_btn", function () {
        const index = $(this)
            .closest(".file-preview-list_single")
            .data("index");
        const input = $(this)
            .closest(".file-upload-parent")
            .find('input[type="file"]')[0];
        const uploader = $(input).data("uploader");
        if (uploader) {
            uploader.removeFile(index);
        }
    });
}

function setupTriggerInputs() {
    document
        .querySelectorAll(".file-upload-parent")
        .forEach(function (uploadSection) {
            const input = uploadSection.querySelector('input[type="file"]');
            const triggerBtn =
                uploadSection.querySelector(".trigger_input_btn");

            if (input && triggerBtn) {
                triggerBtn.addEventListener("click", function () {
                    input.click();
                });
            }
        });
}

function setupTriggerInputs() {
    document
        .querySelectorAll(".file-upload-parent")
        .forEach(function (uploadSection) {
            const input = uploadSection.querySelector('input[type="file"]');
            const triggerBtn =
                uploadSection.querySelector(".trigger_input_btn");

            if (input && triggerBtn) {
                triggerBtn.addEventListener("click", function () {
                    input.click();
                });
            }
        });
}

$(document).ready(function () {
    initializeFileUploads();
    setupTriggerInputs();
});

$(document).on(
    "dynamic-content-loaded",
    initializeFileUploads,
    setupTriggerInputs
);
