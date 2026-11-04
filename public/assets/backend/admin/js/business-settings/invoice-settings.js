let businessIdentity = $('.business-identity');
businessIdentity.on('change', function(){
    let value = $(this).val();
    $('.identity-number').empty().html(value)
    $('#business-identity-value').attr('placeholder', 'Enter '+value);
});
businessIdentity.on('dblclick',function (){
    let isChecked = $(this).prop('checked');
    $(this).prop('checked', !isChecked);
    $('#business-identity-value').attr('placeholder','');
})
