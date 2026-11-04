$(document).ready(function () {
    const $img = $('.upload-file-img');
    const originalSrc = $img.data('default-src') || $img.attr('src');
    const originalDescription = $('#description-page').val();

    $('form').on('reset', function () {
        setTimeout(() => {
            $img.attr('src', originalSrc).show();
            $img.closest('.upload-file').find('.overlay').addClass('show');
            $img.closest('.upload-file').find('.remove_btn').css('opacity', '1');
            $img.closest('.upload-file').find('.upload-file-textbox').css('display', 'none');
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

    $(".delete-page-setup-image").on("click", function () {
        let getText = $("#get-confirm-and-cancel-button-text-for-delete");
        const button = $(this);
        Swal.fire({
            title: getText.data("sure"),
            text: getText.data("text"),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#1455AC",
            cancelButtonColor: "#d33",
            cancelButtonText: getText.data("cancel"),
            confirmButtonText: getText.data("confirm"),
            reverseButtons: true,
        }).then((result) => {
            if (result.value) {
                const id = button.data("id");
                $(`#admin-page-setup-delete-form-${id}`)[0].submit();
            }
        });
    });
});
