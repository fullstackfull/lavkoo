document.addEventListener("DOMContentLoaded", function () {
    let filePonds = document.querySelectorAll(".filepond");

    filePonds.forEach(function (filePondElement) {
        let fileType =
            $(filePondElement).data("file-type") || "application/zip";
        let maxFiles = $(filePondElement).data("max-files") || 1;
        let maxFileSize = $(filePondElement).data("max-file-size") || "10MB";

        function convertToBytes(size) {
            const sizeMatch = size.match(/^(\d+)(KB|MB|GB)$/);
            if (!sizeMatch) return 0;
            let value = parseInt(sizeMatch[1]);
            let unit = sizeMatch[2].toUpperCase();
            return unit === "KB"
                ? value * 1024
                : unit === "MB"
                ? value * 1024 * 1024
                : value * 1024 * 1024 * 1024;
        }

        let maxSizeBytes = convertToBytes(maxFileSize);

        FilePond.setOptions({
            maxFiles: maxFiles,
            maxFileSize: maxSizeBytes,
            checkValidity: true,
            credits: false,
            acceptedFileTypes: [fileType],
            labelIdle: `
                <div class="text-center">
                    <div class="mb-20"><i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i></div>
                    <p class="mb-0 fs-14 mb-1">Select a file or <span class="fw-semibold">Drag & Drop</span> here</p>
                    <div class="mb-0 fs-12">Total file size no more than  ${maxFileSize}</div>
                    <div class="btn btn-outline-primary mt-30">
                        Select File
                    </div>
                </div>
            `,
        });

        // Create a FilePond instance for each input
        let pondInstance = FilePond.create(filePondElement);

        // Add file event
        pondInstance.on("addfile", (error, file) => {
            if (error) return;
            // createToast({
            //     type: "success",
            //     heading: "File Added Successfully",
            //     description: "Your file has been added successfully.",
            //     showCloseBtn: true,
            // });
        });

        // Remove file event
        pondInstance.on("removefile", (error, file) => {
            if (error) return;
            // createToast({
            //     type: "success",
            //     heading: "File Removed Successfully",
            //     description: "Your file has been removed successfully.",
            //     showCloseBtn: true,
            // });
        });

        // Process file error event
        pondInstance.on("processfileerror", (error, file) => {
            // createToast({
            //     type: "error",
            //     heading: "Upload Failed",
            //     description: error.message || "An unknown error occurred.",
            //     showCloseBtn: true,
            // });
        });
        setTimeout(() => {
            document
                .querySelector(".filepond")
                .closest(".filepond--root")
                .querySelector(".filepond--browser")
                .setAttribute("accept", fileType);
        }, 500);
    });
});
