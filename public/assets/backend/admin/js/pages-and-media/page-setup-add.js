$(document).ready(function () {
    const originalDescription = $('#description-page').val();

    $('form').on('reset', function () {
        setTimeout(() => {
            $('#description-page').val(originalDescription);

            const quill = $('#description-page-editor').data('quill');
            if (quill) {
                quill.root.innerHTML = originalDescription;
                $('#description-page').val(originalDescription);
            }
        }, 0);
    });

    setTimeout(() => {
        $('.ql-toolbar .ql-video').hide();
    }, 200);
});
