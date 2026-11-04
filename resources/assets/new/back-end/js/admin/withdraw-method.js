'use strict';
let counter;
function removeField(){
    $('.remove-field').on('click',function (){
        $( `#field-row--${$(this).data('key')}` ).remove();
        counter--;
    })
}
removeField();
jQuery(document).ready(function ($) {
    counter = 1;
    $('#add-more-field').on('click', function (event) {
        let getFiledText = $('#get-add-filed-text');
        let getMessageSelectFieldType= $('.message-select-field-type');
        if(counter < 15) {
            event.preventDefault();
            let html =
                `<div class="bg-section rounded-10 p-12 p-sm-20" id="field-row--${counter}">
                    <div class="row g-3 align-items-end">
                        <div class="col-xl-3 col-lg-6">
                            <label class="form-label mb-2">${getFiledText.data('input-filed')} <span class="text-danger">*</span></label>
                            <select class="form-control js-select" name="field_type[]" required>
                                <option value="" selected disabled>${getMessageSelectFieldType.data('select-field-type')}</option>
                                <option value="string">${getFiledText.data('string')}</option>
                                <option value="number">${getFiledText.data('number')}</option>
                                <option value="date">${getFiledText.data('date')}</option>
                                <option value="password">${getFiledText.data('password')}</option>
                                <option value="email">${getFiledText.data('email')}</option>
                                <option value="phone">${getFiledText.data('phone')}</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="">
                                <label class="form-label mb-2">${getFiledText.data('field-name')} <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="field_name[]"
                                        placeholder="Select field name" value="" required>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="">
                                <label class="form-label mb-2">${getFiledText.data('placeholder-text')} <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="placeholder_text[]"
                                        placeholder="Select placeholder text" value="" required>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="d-flex justify-content-between align-items-end gap-3">
                                <div class="d-flex gap-2 form-control min-h-40">
                                    <input class="form-check-input checkbox--input" type="checkbox" value="1" name="is_required[${counter}]" id="flex-check-default--${counter}" checked>
                                    <label class="form-check-label" for="flex-check-default--${counter}">
                                        ${getFiledText.data('required')}
                                    </label>
                                </div>
                                <span class="btn btn-danger icon-btn remove-field" data-key="${counter}">
                                    <i class="fi fi-rr-trash"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>`;
            $('#custom-field-section').append(html)
            $(".js-select").select2();
            removeField();
            counter++;
        } else {
            Swal.fire({
                title: getFiledText.data('reached-maximum'),
                confirmButtonText: getFiledText.data('confirm'),
            });
        }
    })
    $('form').on('reset', function () {
        if(counter > 1) {
            $('#custom-field-section').html("");
            $('#method_name').val("");
        }
        counter = 1;
    })
});
