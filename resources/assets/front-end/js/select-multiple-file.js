'use strict'

let selectedMultiFiles = [];

$(document).on('ready', () => {

    $("#select-file").on('change', function () {
        for (let index = 0; index < this.files.length; ++index) {
            selectedMultiFiles.push(this.files[index]);
        }

        displaySelectedFiles();
        this.value = null;
    });


    function displaySelectedFiles() {

        const container = document.getElementById("selected-files-container");
        container.innerHTML = "";

        selectedMultiFiles.forEach((file, index) => {

            const input = document.createElement("input");
            input.type = "file";
            input.name = `file[${index}]`;
            input.classList.add(`file-index${index}`);
            input.hidden = true;

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;

            container.appendChild(input);
        });


        let fileArray = $('.file-array');
        fileArray.empty();

        selectedMultiFiles.forEach((file, index) => {

            let fileName = file.name;
            let fileSize = formatBytes(file.size);
            let fileIcon = getFileIcon(fileName);

            let fileDesign =
                '<div class="uploaded-file-item" data-index="'+index+'">' +
                '<img src="' + fileIcon + '" class="file-icon" alt="">' +
                '<div class="upload-file-item-content">'+
                '<div>' + fileName + '</div>' +
                '<small>'+fileSize+'</small>' +
                '</div>' +
                '<button type="button" class="remove-file px-0">' +
                '<i class="fi fi-rr-cross-small"></i>' +
                '</button>' +
                '</div>';

            let $uploadDiv = jQuery.parseHTML(fileDesign);

            fileArray.append($uploadDiv);
        });
    }

    $(document).on('click', '.remove-file', function () {
        let parent = $(this).closest('.uploaded-file-item');
        let index = parent.data('index');
        selectedMultiFiles.splice(index, 1);
        displaySelectedFiles();
    });

    function resetFileSelection(){
        selectedMultiFiles = [];
        $('.file-array').empty();
        $('#selected-files-container').empty();
        $('#select-file').val('');
    }

    $('.chatting-messages-form').on('submit', function () {
        resetFileSelection();
    });


    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }


    function getFileIcon(fileName) {

        let extension = fileName.split('.').pop().toLowerCase();
        let iconPath = $('#get-file-icon');

        switch(extension) {

            case 'doc':
            case 'docx':
                return iconPath.data('word-icon');

            case 'pdf':
                return iconPath.data('pdf-icon');

            case 'zip':
                return iconPath.data('zip-icon');

            default:
                return iconPath.data('default-icon');
        }
    }

});
