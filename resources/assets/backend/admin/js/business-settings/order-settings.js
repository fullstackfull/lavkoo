$(document).ready(function () {
    const initialResponsibility = $('#free-delivery-responsibility').data('default');

    function toggleAdminArea(responsibility) {
        if (responsibility === 'admin') {
            $('#free-delivery-over-amount-admin-area').show();
        } else {
            $('#free-delivery-over-amount-admin-area').hide();
        }
    }

    $('#free-delivery-responsible-admin').click(function () {
        toggleAdminArea('admin');
    });

    $('#free-delivery-responsible-vendor').click(function () {
        toggleAdminArea('seller');
    });

    $('button[type="reset"]').click(function () {
        setTimeout(function () {
            toggleAdminArea(initialResponsibility);
        }, 10);
    });

    toggleAdminArea(initialResponsibility);
});
