$(document).ready(function () {
    $(function () {
        let coba_image = $('#coba-image').data('url');
        let extension_error = $('#extension-error').data('text');
        let size_error = $('#size-error').data('text');
        let maxFileSize = 2 * 1024 * 1024;

        $("#coba").spartanMultiImagePicker({
            fieldName: 'identity_image[]',
            maxCount: 5,
            rowHeight: '200px',
            rowWidth: '200px',
            groupClassName: 'col-12 col-md-6',
            maxFileSize: maxFileSize,
            placeholderImage: {
                image: coba_image,
                width: '100%'
            },
            dropFileLabel: "Drop Here",
            onAddRow: function (index, file) {

            },
            onRenderedPreview: function (index) {

            },
            onRemoveRow: function (index) {

            },
            onExtensionErr: function (index, file) {
                toastMagic.error(extension_error);
            },
            onSizeErr: function (index, file) {
                toastMagic.error(size_error);
            }
        });
    });
    function checkPasswordMatch() {
        const password = $('#user_password').val();
        const confirmPassword = $('#confirm_password').val();

        if (confirmPassword.length > 0 && password !== confirmPassword) {
            $('.confirm-password-error').text('Password and confirm password does not match.');
        } else {
            $('.confirm-password-error').text('');
        }
    }
    $('#user_password, #confirm_password').on('keyup change', function () {
        checkPasswordMatch();
    });
});
