'use strict';

$('#add-payment-info-form').on('submit', function (event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        type: 'POST',
        url: $(this).attr('action'),
        data: $(this).serialize(),
        success: function (response) {
            if (response.status) {
                toastMagic.success(response.message);
                setTimeout(() => {
                    location.reload();
                }, 2000)
            } else {
                toastMagic.error(response.message);
            }
        },
        error: function (xhr, errors) {
            xhrErrorFunctionality(xhr)
        }
    });
});

$('.edit-payment-info-form').on('submit', function (event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content"),
        },
    });
    $.ajax({
        type: 'POST',
        url: $(this).attr('action'),
        data: $(this).serialize(),
        success: function (response) {
            if (response.status) {
                toastMagic.success(response.message);
                setTimeout(() => {
                    location.reload();
                }, 2000)
            } else {
                toastMagic.error(response.message);
            }
        },
        error: function (xhr) {
            xhrErrorFunctionality(xhr)
        }
    });
});

$('.payment_method').on('change', function () {
    let id = $(this).val().trim();
    let isEdit = $(this).closest('.edit-payment-offcanvas').length > 0;
    $.ajax({
        type: 'GET',
        url: $('#route-shop-payment-information-methods').data('route'),
        data: {
            id: id,
            _token: $('meta[name="_token"]').attr("content")
        },
        success: function (response) {
            if (isEdit) {
                $('.dynamic_fields_wrapper-edit').empty().html(response.htmlView);
            } else {
                $('.dynamic_fields_wrapper').empty().html(response.htmlView);
            }
            // try {
            //     initializeIntlTelInput()
            // } catch (e){}
        },
        error: function (xhr, status, error) {
            xhrErrorFunctionality(xhr)
        }
    });
});


function xhrErrorFunctionality(xhr) {
    if (xhr.status === 422) {
        let errors = xhr.responseJSON.errors;
        let errorsIndex = 1;
        $.each(errors, function (index, messages) {
            $.each(messages, function (item, message) {
                setTimeout(() => {
                    toastMagic.error(message);
                }, errorsIndex * 500);
                errorsIndex++;
            });
        });
    } else {
        toastMagic.error("Something went wrong");
    }
}

function setupCounters() {
    const inputs = document.querySelectorAll('.countable-input');
    inputs.forEach(input => {
        const counter = input.nextElementSibling;
        if (!counter || !counter.classList.contains('char-count')) return;

        const updateCount = () => {
            counter.textContent = input.value.length;
        };

        input.addEventListener('input', updateCount);
        updateCount();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    setupCounters();
});



