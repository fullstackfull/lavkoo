"use strict";

$('#from_date,#to_date').change(function () {
    let fr = $('#from_date').val();
    let to = $('#to_date').val();
    if (fr != '') {
        $('#to_date').attr('required', 'required');
    }
    if (to != '') {
        $('#from_date').attr('required', 'required');
    }
    if (fr != '' && to != '') {
        if (fr > to) {
            $('#from_date').val('');
            $('#to_date').val('');
            toastMagic.error('Invalid date range!');
        }
    }

});

$("#date_type").change(function () {
    let val = $(this).val();
    $('#from_div').toggle(val === 'custom_date');
    $('#to_div').toggle(val === 'custom_date');

    if (val === 'custom_date') {
        $('#from_date').attr('required', 'required');
        $('#to_date').attr('required', 'required');
    } else {
        $('#from_date').val(null).removeAttr('required')
        $('#to_date').val(null).removeAttr('required')
    }
}).change();


// Dognut Pie Chart
var options = {
    series: [
            $('#digital_payment').data('text'),
            $('#cash_payment').data('text'),
            $('#wallet_payment').data('text'),
            $('#offline_payment').data('text'),
            $('#return_amount').data('text')
        ],
    chart: {
        width: 320,
            type: 'donut',
    },
    labels: [
        $('#digital_payment_text').data('text') + " - " + $('#currency_symbol').data('text') + $('#digital_payment_format').data('text'),
        $('#cash_payment_text').data('text') + " - " + $('#currency_symbol').data('text') + $('#cash_payment_format').data('text'),
        $('#wallet_payment_text').data('text') + " - " + $('#currency_symbol').data('text') + $('#wallet_payment_format').data('text'),
        $('#offline_payment_text').data('text') + " - " + $('#currency_symbol').data('text') + $('#offline_payment_format').data('text'),
        $('#return_amount_text').data('text') + " - " + $('#currency_symbol').data('text') + $('#return_amount_format').data('text'),
    ],
    dataLabels: {
        enabled: false,
            style: {
            colors: ['#004188', '#A2CEEE', '#0177CD', '#7b94a4', '#FFA500']
        }
    },
    responsive: [{
        breakpoint: 1650,
        options: {
            chart: {
                width: 260
            },
        }
    }],
        colors: ['#004188', '#A2CEEE', '#0177CD', '#7b94a4', '#FFA500'],
        fill: {
        colors: ['#004188', '#A2CEEE', '#0177CD', '#7b94a4', '#FFA500']
    },
    legend: {
        show: false
    },
};

var chart = new ApexCharts(document.querySelector("#dognut-pie"), options);
chart.render();
