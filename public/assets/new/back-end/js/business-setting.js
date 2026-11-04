
$('#update-settings').on('submit', function (e) {
    let minimum_add_fund_amount = parseFloat($('#minimum_add_fund_amount').val());
    let maximum_add_fund_amount = parseFloat($('#maximum_add_fund_amount').val());
    if (maximum_add_fund_amount < minimum_add_fund_amount) {
        e.preventDefault();
        toastMagic.error($('#get-minimum-amount-message').data('error'));
    }
});
$('#discount_amount, #validity').on('input', function () {
   sanitizeAndValidateQuantityInput(this);
});
function sanitizeAndValidateQuantityInput(inputElement) {
    inputElement.value = inputElement.value.replace(/[^0-9]/g, '').replace(/^0+/, '');
    const min = parseInt(inputElement.getAttribute("min")) || 1;
    const max = parseInt(inputElement.getAttribute("max")) || 100;
    const val = parseInt(inputElement.value);

    if (inputElement.value !== '' && (val < min || val > max)) {
        inputElement.value = min;
    }
}
