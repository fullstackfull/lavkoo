
let paymentGatewayPublishedStatus = $('#payment-gateway-published-status').data('status');

if (paymentGatewayPublishedStatus?.toString() === 'true') {
    'use strict';
    let smsGatewayCards = $('#sms-gateway-cards');
    smsGatewayCards.find('input').each(function () {
        $(this).attr('disabled', true);
    });
    smsGatewayCards.find('select').each(function () {
        $(this).attr('disabled', true);
    });
    smsGatewayCards.find('.switcher_input').each(function () {
        $(this).removeAttr('checked', true);
    });
    smsGatewayCards.find('button').each(function () {
        $(this).attr('disabled', true);
    });
}

