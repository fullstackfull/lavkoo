"use strict";

$(".js-example-responsive").select2({
    width: 'resolve'
});

function readURL(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();

        reader.onload = function (e) {
            $('#viewer').attr('src', e.target.result);
        }

        reader.readAsDataURL(input.files[0]);
    }
}

$("#customFileEg1").change(function () {
    readURL(this);
});

$(function () {
    let coba_image = $('#coba-image').data('url');
    let extension_error = $('#extension-error').data('text');
    let size_error = $('#size-error').data('text');
    let maxFileSize = 2 * 1024 * 1024;

    $("#coba").spartanMultiImagePicker({
        fieldName: 'identity_image[]',
        maxCount: 5,
        rowHeight: '200px',
        groupClassName: 'col-6 col-md-4',
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

$('.deliveryman_status_form').on('submit', function (event){
    event.preventDefault();

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
        }
    });
    $.ajax({
        url:$(this).attr('action'),
        method: 'POST',
        data: $(this).serialize(),
        success: function (data) {
            toastMagic.success($('#deliveryman-status-message').data('text'));
        }
    });
});

$('#from_date,#to_date').change(function () {
    let fr = $('#from_date').val();
    let to = $('#to_date').val();
    if(fr != ''){
        $('#to_date').attr('required','required');
    }
    if(to != ''){
        $('#from_date').attr('required','required');
    }
    if (fr != '' && to != '') {
        if (fr > to) {
            $('#from_date').val('');
            $('#to_date').val('');
            toastMagic.error('Invalid date range!');
        }
    }

})
$('.show-more-content').hide();

$(document).on('click','.toggle-more',function (){
    let id = $(this).data('id');

    $('#show-more-'+id).hide();
    $('#show-more-content-'+id).show();
});

$(document).on('click','.toggle-less',function (){
    let id = $(this).data('id');

    $('#show-more-content-'+id).hide();
    $('#show-more-'+id).show();
});

$('.order-status-history').on('click', function (){
    let url = $('.status-history-url').data('url');
    let id = $(this).data('id');
    url = url.replace(":id", id)
    $.ajax({
        url: url,
        method: 'GET',
        success: function (data) {
            $(".load-with-ajax").empty().append(data);
        }
    });
});

function getSelectedFilters() {
    let checkedStatusValuesArray = [];
    let checkedPaymentValuesArray = [];

    $('#status input[type=checkbox]:checked').each(function () {
        checkedStatusValuesArray.push($(this).val());
    });

    $('#payment input[type=checkbox]:checked').each(function () {
        checkedPaymentValuesArray.push($(this).val());
    });

    return {
        order_status: checkedStatusValuesArray,
        payment_status: checkedPaymentValuesArray
    };
}

function updateExportUrl() {
    const filters = getSelectedFilters();
    let baseUrl = $('.earning-file-export-btn').data('base');

    const hasQuery = baseUrl.includes('?');
    const params = new URLSearchParams();

    if (filters.order_status.length > 0) {
        params.append('order_status', filters.order_status.join(','));
    }

    if (filters.payment_status.length > 0) {
        params.append('payment_status', filters.payment_status.join(','));
    }

    const finalUrl = baseUrl + (hasQuery ? '&' : '?') + params.toString();

    $('.earning-file-export-btn').attr('href', finalUrl);
}


$('.earning-order-history input[type=checkbox]').on('change', function () {
    const filters = getSelectedFilters();
    updateExportUrl();

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $.get({
        url: $('#get-filter-route').data('action'),
        data: {
            order_status: filters.order_status,
            payment_status: filters.payment_status,
            page: 1
        },
        beforeSend: function () {
            $('#loading').fadeIn();
        },
        success: function (data) {
            $('#status-wise-view').html(data.view);
            $('#row-count').html(data.count);
        },
        complete: function () {
            $('#loading').fadeOut();
        }
    });
});

$(document).on('click', '#status-wise-view .pagination a', function(e) {
    e.preventDefault();

    const url = $(this).attr('href');
    const page = new URL(url).searchParams.get('page');
    const filters = getSelectedFilters();

    $.get({
        url: $('#get-filter-route').data('action'),
        data: {
            order_status: filters.order_status,
            payment_status: filters.payment_status,
            page: page
        },
        beforeSend: function () {
            $('#loading').fadeIn();
        },
        success: function (data) {
            $('#status-wise-view').html(data.view);
            $('#row-count').html(data.count);
        },
        complete: function () {
            $('#loading').fadeOut();
        }
    });
});

$('.earning-file-export').on('click',function (){
    let checkedStatusValuesArray = [];
    let checkedPaymentValuesArray = [];
    $('#status input[type=checkbox]:checked').map(function() {
        checkedStatusValuesArray.push($(this).val());
    }).get();
    $('#payment input[type=checkbox]:checked').map(function() {
        checkedPaymentValuesArray.push($(this).val());
    }).get();
    let queryParams = '&order_status=' + checkedStatusValuesArray.join(',') +
        '&payment_status=' + checkedPaymentValuesArray.join(',');
    window.location.href = $(this).data('action')+queryParams;
});
