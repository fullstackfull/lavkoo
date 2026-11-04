document.addEventListener("DOMContentLoaded", function () {
    const uploadComponents = document.querySelectorAll(".multiple_file");

    uploadComponents.forEach((component) => {
        const fileInput = component.querySelector("input[type='file']");

        if (!fileInput) return;

        const fileViewList = component
            .closest(".file_upload_wrapper")
            .querySelector(".file-view-list");
        const uploadedFiles = [];

        const maxSizeStr = fileInput.dataset.maxSize || "unlimited";
        const maxCount = fileInput.dataset.maxCount
            ? parseInt(fileInput.dataset.maxCount, 10)
            : Infinity;
        const maxTotalSizeStr = fileInput.dataset.maxTotalSize || "128mb";

        const maxSize = parseSize(maxSizeStr);
        const maxTotalSize = parseSize(maxTotalSizeStr);

        let currentTotalSize = 0;

        fileInput.addEventListener("change", function (e) {
            const files = Array.from(e.target.files);

            if (uploadedFiles.length + files.length > maxCount) {
                toastMagic.error(
                    `You can only upload up to ${maxCount} files.`
                );
                return;
            }

            for (const file of files) {
                if (file.size > maxSize) {
                    toastMagic.error(
                        `The file "${
                            file.name
                        }" is too large. Maximum allowed size is ${(
                            maxSize /
                            (1024 * 1024)
                        ).toFixed(2)} MB.`
                    );
                    continue;
                }

                if (currentTotalSize + file.size > maxTotalSize) {
                    toastMagic.error(
                        `The total size of selected files exceeds the maximum allowed size of ${(
                            maxTotalSize /
                            (1024 * 1024)
                        ).toFixed(2)} MB.`
                    );
                    break;
                }

                uploadedFiles.push(file);
                currentTotalSize += file.size;
                addFileToView(file);
            }

            e.target.value = "";
        });

        function parseSize(sizeStr) {
            if (sizeStr === "unlimited") return Infinity;

            const sizeRegex = /^(\d+)(kb|mb|gb)$/i;
            const match = sizeStr.match(sizeRegex);
            if (!match) return 0;

            const size = parseInt(match[1], 10);
            const unit = match[2].toLowerCase();

            switch (unit) {
                case "kb":
                    return size * 1024;
                case "mb":
                    return size * 1024 * 1024;
                case "gb":
                    return size * 1024 * 1024 * 1024;
                default:
                    return 0;
            }
        }

        function addFileToView(file) {
            const fileDiv = document.createElement("div");
            fileDiv.classList.add("file-view-list_single");
            fileDiv.dataset.filename = file.name;

            const fileName = file.name;
            const fileSize =
                file.size < 1024 * 1024
                    ? (file.size / 1024).toFixed(2) + " KB"
                    : (file.size / (1024 * 1024)).toFixed(2) + " MB";

            fileDiv.innerHTML = `
                <button type="button" class="btn btn-danger btn-circle fs-10 close_btn">
                    <i class="fi fi-sr-cross"></i>
                </button>
                <div class="bg-section p-3 rounded-10 d-flex gap-3 justify-content-between align-items-center">
                    <div class="d-flex gap-2 justify-content-between align-items-center">
                        <img width="24" height="24" src="${iconPath}" alt="file">
                        <p class="fs-12 mb-0 line-1 max-w-180">${fileName}</p>
                    </div>
                    <h5 class="mb-0 text-body">${fileSize}</h5>
                </div>
            `;

            fileViewList.appendChild(fileDiv);

            const closeButton = fileDiv.querySelector(".close_btn");
            closeButton.addEventListener("click", function () {
                removeFileFromView(fileDiv, file);
            });
        }

        function removeFileFromView(fileDiv, file) {
            const index = uploadedFiles.indexOf(file);
            if (index !== -1) {
                uploadedFiles.splice(index, 1);
                currentTotalSize -= file.size;
            }
            fileDiv.remove();
        }
    });
});
