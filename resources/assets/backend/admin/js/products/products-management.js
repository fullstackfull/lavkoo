"use strict";

let getYesWord = $('#message-yes-word').data('text');
let getCancelWord = $('#message-cancel-word').data('text');
let messageAreYouSureDeleteThis = $('#message-are-you-sure-delete-this').data('text');
let messageYouWillNotAbleRevertThis = $('#message-you-will-not-be-able-to-revert-this').data('text');

$('.attribute-delete-button').on('click', function () {
    let attributeId = $(this).attr("id");
    Swal.fire({
        title: messageAreYouSureDeleteThis,
        text: messageYouWillNotAbleRevertThis,
        showCancelButton: true,
        confirmButtonColor: '#1455AC',
        cancelButtonColor: '#d33',
        confirmButtonText: getYesWord,
        cancelButtonText: getCancelWord,
        icon: 'warning',
        reverseButtons: true
    }).then((result) => {
        if (result.value) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
                }
            });
            $.ajax({
                url: $('#route-admin-attribute-delete').data('url'),
                method: 'POST',
                data: {id: attributeId},
                success: function (response) {
                    toastMagic.success(response.message);
                    location.reload();
                }
            });
        }
    })
})

$('.delete-brand').on('click', function () {
    let brandId = $(this).attr("id");
    let brands =$('#get-brands').data('brands').data
    brands = brands.filter(brands =>brands.id !== parseInt(brandId));
    let selectDropdown = $('.brand-option').empty();
    brands.forEach(brand => {
        let option = $('<option></option>').attr('value', brand.id).text(brand.name);
        selectDropdown.append(option);
    });
    $('input[name=id]').val(brandId)
    $('.brand-title-message').html($(this).data('text'))
    if($(this).data('product-count') > 0){
        $('#deleteModal-'+brandId).modal('hide');
        $('#select-brand-modal').modal('show');
    }else{
        $('.product-brand-update-form-submit').submit();
    }
});

$('.delete-category').on('click', function () {
    let categoryId = $(this).attr("id");
    Swal.fire({
        title: messageAreYouSureDeleteThis,
        text: messageYouWillNotAbleRevertThis,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1455AC',
        cancelButtonColor: '#d33',
        confirmButtonText: getYesWord,
        cancelButtonText: getCancelWord,
        reverseButtons: true
    }).then((result) => {
        if (result.value) {
            let categories =$('#get-categories').data('categories').data
            categories = categories.filter(category =>category.id !== parseInt(categoryId));
            let selectDropdown = $('.category-option').empty();
            categories.forEach(category => {
                let option = $('<option></option>').attr('value', category.id).text(category.name);
                selectDropdown.append(option);
            });
            $('input[name=id]').val(categoryId)

            $('.category-title-message').html($(this).data('text'))
            if($(this).data('product-count') > 0){
                $('#select-category-modal').modal('show');
            }else{
                $('.product-category-update-form-submit').submit();
            }

        }
    })
});

$('.category-delete-button').on('click', function () {
    let categoryId = $(this).attr("id");
    Swal.fire({
        title: messageAreYouSureDeleteThis,
        text: messageYouWillNotAbleRevertThis,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1455AC',
        cancelButtonColor: '#d33',
        confirmButtonText: getYesWord,
        cancelButtonText: getCancelWord,
        reverseButtons: true
    }).then((result) => {
        if (result.value) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
                }
            });
            $.ajax({
                url: $('#route-admin-category-delete').data('url'),
                method: 'POST',
                data: {id: categoryId},
                success: function (response) {
                    toastMagic.success(response.message);
                    location.reload();
                }
            });
        }
    })
});

$('.action-get-sub-category-onchange').on('change', function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        type: 'POST',
        url: $(this).data('route'),
        data: {
            id: $(this).val()
        },
        success: function (response) {
            $("#parent_id").html(response.html);
        }
    });
});


let loadMoreParentCategoriesPage = 2;
$('.load-more-parent-categories').on('click', function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        type: 'POST',
        url: $(this).data('route'),
        data: {
            page: loadMoreParentCategoriesPage,
            old_categories: $('[name="old_categories"]').val(),
        },
        beforeSend: function () {
            $('.load-more-categories-spinner').removeClass('d-none');
            $('.load-more-parent-categories').addClass('d-none');
        },
        success: function (response) {
            loadMoreParentCategoriesPage++;
            $("#load-more-categories-view").append(response.html);
            if (response.hiddenCount <= 0) {
                $('.load-more-categories-container').hide();
            }

            setTimeout(() => {
                $('.load-more-categories-spinner').addClass('d-none');
                $('.load-more-parent-categories').removeClass('d-none');
            }, 1000)
        },
        complete: function () {
            setTimeout(() => {
                $('.load-more-categories-spinner').addClass('d-none');
                $('.load-more-parent-categories').removeClass('d-none');
            }, 1000)
        },
    });
});


$(document).on('submit', '.admin-category-store', async function (event) {
    event.preventDefault();

    if (!await validateFormHelper($(this))) return false;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
    });

    const formData = new FormData(this);
    $.ajax({
        url: $(this).attr('action'),
        type: $(this).attr('method') || 'POST',
        data: formData,
        processData: false,
        contentType: false,
        beforeSend: function () {
            // Loader ON
        },
        success: function (response) {
            // Success handler
            ajaxResponseManager(response);
        },
        error: function (xhr) {
            xhrResponseManager(xhr);
        },
        complete: function () {
            formSubmitCleanup($(this));
        }
    });
});


$(document).ready(function() {
    $('.attribute-edit-btn').on('click', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const defaultLang = $('#default-language').data('lang');
        const action = $(this).data('action');

        $('#attribute-id').val(id);
        $(`#name-${defaultLang}`).val(name);
        $.ajax({
            url: action,
            type: 'GET',
            success: function(response) {
                const translations = response.translations || [];
                $('.attribute-name-input').each(function() {
                    const lang = $(this).data('lang');
                    if (lang === defaultLang) return;
                    const match = translations.find(t =>
                        t.locale === lang && t.key === "name"
                    );
                    $(this).val(match ? match.value : '');
                });
            },
            error: function() {
                console.warn("Failed to load translations.");
            }
        });

        $('#submit-btn').text('Update');
        $('#cancel-edit-btn').show();
        $('#reset-btn').hide();
        const row = document.querySelector('.product-attribute-management');
        const top = row.getBoundingClientRect().top + window.pageYOffset - 100;
        window.scrollTo({ top, behavior: 'smooth' });

        setTimeout(() => $('.attribute-name-input').first().focus(), 600);
    });

    // Reset
    $('#cancel-edit-btn, #reset-btn').on('click', function() {
        resetForm();
    });

    function resetForm() {
        $('#attribute-form')[0].reset();
        $('#attribute-id').val('');
        $('.attribute-name-input').val('');
        $('#submit-btn').text('Submit');
        $('#cancel-edit-btn').hide();
        $('#reset-btn').show();
    }
    $('.brand-edit-btn').on('click', function() {
        $('.brand-edit-back-btn').removeClass('d-none');
    });
});

document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".offcanvas").forEach(function (canvas) {
        canvas.addEventListener("hidden.bs.offcanvas", function () {
            canvas.querySelector(".brand-edit-back-btn")?.classList.add("d-none");
        });
    });
});
