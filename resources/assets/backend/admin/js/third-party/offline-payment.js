"use strict";

function removeInputFieldsGroup() {
    $(".remove-input-fields-group").on("click", function() {
        $("#" + $(this).data("id")).remove();
    });
}
removeInputFieldsGroup();
let counter = 1;
$("#add-input-fields-group").on("click", function() {
    let getAddInputText = $("#get-add-input-field-text");
    let id = Math.floor((Math.random() + 1) * 9999);
    let newField =
        `<div class="p-12 p-sm-20 bg-section rounded mt-3" id="` +
        id +
        `">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="input_name" class="form-label">${getAddInputText.data(
                                        "input-field-name"
                                    )}</label>
                                    <input type="text" name="input_name[]" class="form-control" placeholder="${getAddInputText.data(
                                        "input-field-name-placeholder"
                                    )}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="input_data" class="form-label">${getAddInputText.data(
                                        "input-data"
                                    )}</label>
                                    <input type="text" name="input_data[]" class="form-control" placeholder="${getAddInputText.data(
                                        "input-data-placeholder"
                                    )}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn btn-danger icon-btn remove-input-fields-group" title="${getAddInputText.data(
                                            "delete-text"
                                        )}" data-id="${id}">
                                            <i class="fi fi-rr-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;

    $("#input-fields-section").append(newField);
    $("#" + id).fadeIn();
    removeInputFieldsGroup();
});

$("#add-customer-input-fields-group").on("click", function() {
    let id = Math.floor((Math.random() + 1) * 9999);
    let getCustomerAddInputText = $("#get-add-customer-input-field-text");
    if (counter < 100) {
        $("#customer-input-fields-section").append(
            `<div class="p-12 p-sm-20 bg-section rounded mt-3" id="` +
                id +
                `">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">${getCustomerAddInputText.data(
                                "input-field-name"
                            )}</label>
                            <input type="text" name="customer_input[]" class="form-control" placeholder="${getCustomerAddInputText.data(
                                "input-field-name-placeholder"
                            )}" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="customer_placeholder" class="form-label">${getCustomerAddInputText.data(
                                "input-placeholder"
                            )}</label>
                            <input type="text" name="customer_placeholder[]" class="form-control" placeholder="${getCustomerAddInputText.data(
                                "input-placeholder-placeholder"
                            )}" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between h-100 gap-2">
                            <div class="form-check text-start mb-2 align-self-end">
                                <label class="form-check-label text-dark" for="` +
                id +
                1 +
                `">
                                    <input type="checkbox" class="form-check-input" id="` +
                id +
                1 +
                `" name="is_required[${counter}]"> ${getCustomerAddInputText.data(
                    "require-text"
                )}
                                </label>
                            </div>

                            <button type="button" class="btn btn-danger delete icon-btn remove-input-fields-group" title="${getCustomerAddInputText.data(
                                "delete-text"
                            )}"  data-id="${id}">
                                <i class="fi fi-rr-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>`
        );
        counter++;
    }
    $("#" + id).fadeIn();
    removeInputFieldsGroup();
});

$(".method-status-form").on("submit", function(event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content")
        }
    });
    $.ajax({
        url: $(this).attr("action"),
        method: $(this).attr("method"),
        data: $(this).serialize(),
        success: function(data) {
            if (parseInt(data.success_status) === 1) {
                toastMagic.success(data.message);
            } else if (parseInt(data.success_status) === 0) {
                toastMagic.error(data.message);
            }
            setTimeout(function() {
                location.reload();
            }, 1000);
        }
    });
});

$("#payment-method-offline").on("submit",  function(event) {
    event.preventDefault();
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content")
        }
    });
    $.ajax({
        url: $(this).attr("action"),
        method: $(this).attr("method"),
        data: $(this).serialize(),
        success: function(data) {
            if (parseInt(data.status) === 1) {
                toastMagic.success(data.message);
               setTimeout(function(){
                   location.href = data.redirect_url;
               },1000);
            } else {
                toastMagic.error(data.message);
            }
        },
        error: function (xhr, status, error) {
            if (xhr.status === 422 && xhr.responseJSON.errors) {
                let errors = xhr.responseJSON.errors;
                let delay = 0;
                $.each(errors, function (key, messages) {
                    $.each(messages, function (i, msg) {
                        setTimeout(function () {
                            toastMagic.error(msg);
                        }, delay);
                        delay += 700;
                    });
                });

            } else if(xhr.responseJSON && xhr.responseJSON.message) {

                toastMagic.error(xhr.responseJSON.message);
            }
        },
    });
});
