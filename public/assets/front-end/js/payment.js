"use strict";

setTimeout(function () {
    $(".stripe-button-el").hide();
    $(".razorpay-payment-button").hide();
}, 10);

$(function () {
    $(".proceed_to_next_button").addClass("disabled");
});

const radioButtons = document.querySelectorAll('input[type="radio"]');
radioButtons.forEach((radioButton) => {
    radioButton.addEventListener("change", function () {
        radioButtons.forEach((otherRadioButton) => {
            if (otherRadioButton !== this) {
                otherRadioButton.checked = false;
            }
        });
        if (this.checked) {
            this.setAttribute("checked", true);
        } else {
            this.setAttribute("checked", false);
        }
        updateProceedButtonState()
    });
});

function updateProceedButtonState() {
    let paymentInputCheckbox = $('.payment-input-checkbox').length === $('.payment-input-checkbox:checked').length;

    let radioStatus = false;
    let payOfflineSelected = false;

    radioButtons.forEach((radio) => {
        if (radio.checked) {
            if (radio.id === 'pay_offline') {
                payOfflineSelected = true;
            } else {
                radioStatus = true;
            }
        }
    });

    if (paymentInputCheckbox && radioStatus) {
        $(".proceed_to_next_button").removeClass("disabled");
    } else {
        $(".proceed_to_next_button").addClass("disabled");
    }

    // show/hide offline card
    if (payOfflineSelected) {
        $(".pay_offline_card").removeClass("d-none");
        $(".proceed_to_next_button").addClass("disabled");
    } else {
        $(".pay_offline_card").addClass("d-none");
    }
}

$('.payment-input-checkbox').on('click', function () {
    if (this.checked) {
        $('.payment-input-checkbox').prop('checked', true);
    }
    updateProceedButtonState();
});

updateProceedButtonState();


function checkoutFromPayment() {
    let checked_button_id = $('input[type="radio"]:checked').attr("id");
    $(".action-checkout-function").attr("disabled", true).addClass("disabled");
    $("#" + checked_button_id + "_form").submit();
}

const buttons = document.querySelectorAll(".offline_payment_button");
const selectElement = document.getElementById("pay_offline_method");
buttons.forEach((button) => {
    button.addEventListener("click", function () {
        const buttonId = this.id;
        const editDueAmount = this.dataset.editDueAmount;
        pay_offline_method_field(buttonId, editDueAmount);
        selectElement.value = buttonId;
    });
});

$("#pay_offline_method").on("change", function () {
    pay_offline_method_field(this.value);
});

function pay_offline_method_field(method_id, edit_due_amount = null, isModalShow = true, orderId = null) {
    $.ajaxSetup({
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
    });

    let url = $("#route-pay-offline-method-list").data("url") + "?method_id=" + method_id;
    if (edit_due_amount !== null) {
        url += "&edit_due_amount=" + encodeURIComponent(edit_due_amount);
    }
    const targetSelector = orderId ? `#payment_method_field_${orderId}` : '#payment_method_field';


    $.ajax({
        url: url,
        type: "get",
        processData: false,
        contentType: false,
        beforeSend: function () {
            showGlobalLoader();
        },
        success: function (response) {
            $(targetSelector).html(response.methodHtml);
            if (isModalShow) {
                $("#selectPaymentMethod").modal().show();
            }
        },
        error: function () {
            console.error("Failed to fetch offline payment method.");
        },
        complete: function () {
            hideGlobalLoader();
        }
    });
}

$("#bring_change_amount").on("shown.bs.collapse", function () {
    $("#bring_change_amount_btn").text($(this).data("less"));
});

$("#bring_change_amount").on("hidden.bs.collapse", function () {
    $("#bring_change_amount_btn").text($(this).data("more"));
});

$(document).ready(function () {
    $("input").on("change", function () {
        bringChangeAmountSectionRender();
    });

    function bringChangeAmountSectionRender() {
        if ($("#cash_on_delivery").prop("checked")) {
            $(".bring_change_amount_section").slideDown();
        } else {
            $(".bring_change_amount_section").slideUp();
        }
    }

    $("#bring_change_amount_input").on("keyup keypress change", function () {
        $("#bring_change_amount_value").val($(this).val());
    });
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.order-choose-payment-method-modal')
        .forEach(modal => {
            initOrderChoosePaymentMethodModal(modal);
        });

    const offlineMethodSelects = document.querySelectorAll('.pay_offline_method');
    offlineMethodSelects.forEach(select => {
        select.addEventListener('change', function () {
            const methodId = this.value;
            const orderId = this.dataset.orderId;
            const orderEditDue = this.dataset.editDue;
            console.log(methodId, orderId, orderEditDue);
            if (methodId) {
                pay_offline_method_field(methodId, orderEditDue, false, orderId);
            }
        });
    });

    const orderDetailsSection = document.getElementById('order-details-section');
    const paymentMethodSection = document.getElementById('payment-method-section');
    const offlinePaymentSection = document.getElementById('offline-payment-section');
    const orderModal = document.getElementById('order-details');
    const backToPaymentMethod = document.querySelector('.back-to-payment-method');

    function showPaymentMethodSection() {
        if (orderDetailsSection) orderDetailsSection.classList.add('d-none');
        if (paymentMethodSection) paymentMethodSection.classList.remove('d-none');
        if (offlinePaymentSection) offlinePaymentSection.classList.add('d-none');
    }

    function showOrderDetailsSection() {
        if (orderDetailsSection) orderDetailsSection.classList.remove('d-none');
        if (paymentMethodSection) paymentMethodSection.classList.add('d-none');
        if (offlinePaymentSection) offlinePaymentSection.classList.add('d-none');
    }

    function hideModalHeaderSection() {
        const modalHeaderSection = document.querySelectorAll('.modal-header-section');
        modalHeaderSection.forEach(el => {
            el.classList.remove('d-flex');
            el.classList.add('d-none');
        });
        console.log(modalHeaderSection);
    }

    function showModalHeaderSection() {
        const modalHeaderSection = document.querySelectorAll('.modal-header-section');
        modalHeaderSection.forEach(el => {
            el.classList.add('d-flex');
            el.classList.remove('d-none');
        });
    }


    document.addEventListener('click', function (e) {
        if (e.target.closest('.pay-now-btn')) {
            e.preventDefault();
            hideModalHeaderSection();
            showPaymentMethodSection();
        }

        if (e.target.closest('.back-to-order')) {
            e.preventDefault();
            showModalHeaderSection();
            showOrderDetailsSection();
        }
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target.id.startsWith('choosePaymentMethodModal-')) {
            try {
                document.querySelectorAll('.cash-on-delivery-section').forEach(section => {
                    section.classList.remove('d-none');
                });
                document.querySelectorAll('.offline-payment-section').forEach(section => {
                    section.classList.add('d-none');
                });
                try {
                    document.querySelectorAll('.pay_offline').forEach(payOffline => {
                        payOffline.checked = false;
                    });
                    document.querySelectorAll('.pay_offline_card').forEach(card => card.classList.add('d-none'));
                } catch (e) {
                }
            } catch (e) {

            }
        }
    });

    backToPaymentMethod?.addEventListener('click', function () {
        if (orderDetailsSection) orderDetailsSection.classList.add('d-none');
        if (paymentMethodSection) paymentMethodSection.classList.remove('d-none');
        if (offlinePaymentSection) offlinePaymentSection.classList.add('d-none');
    });

    if (orderModal) {
        orderModal.addEventListener('hidden.bs.modal', function () {
            showOrderDetailsSection();
        });
    }
});

function initOrderChoosePaymentMethodModal(modal) {
    const codCards = modal.querySelectorAll('.cod-for-cart');
    const walletCards = modal.querySelectorAll('.pay-via-wallet');
    const walletInfos = modal.querySelectorAll('.wallet-info-section');
    const digitalPaymentCards = modal?.querySelectorAll('.pay-via-digital');
    const digitalInfos = modal?.querySelectorAll('.digital-info-section');
    const gatewayButtons = modal?.querySelectorAll('.digital-payment-card');
    const payOfflines = modal.querySelectorAll('.pay_offline');
    const paymentRadios = modal.querySelectorAll('input[name="payment_method"]');
    const paymentMethodInput = modal.querySelector('input[type="hidden"][name="payment_method"]');
    const offlineButtons = modal.querySelectorAll('.offline_payment_method_button');
    const codSections = modal.querySelectorAll('.cash-on-delivery-section');
    const offlineSections = modal.querySelectorAll('.offline-payment-section');
    const bringChangeSections = modal.querySelectorAll('.bring_change_amount_section');
    const payOfflineCards = modal.querySelectorAll('.pay_offline_card');
    const backToCODs = modal.querySelectorAll('.back-to-cod');
    const paymentProceedBtns = modal.querySelectorAll('.payment-proceed-btn');

    const orderDetailsSection = modal.querySelector('#order-details-section');
    const paymentMethodSection = modal.querySelector('#payment-method-section');
    const offlinePaymentSection = modal.querySelector('#offline-payment-section');
    const codDigitalSection = modal.querySelector('.cod-digital-payment-section');

    function disableProceedButtons() {
        paymentProceedBtns.forEach(btn => {
            btn.disabled = true;
            btn.classList.add('disabled');
            btn.setAttribute('aria-disabled', 'true');
        });
    }

    function enableProceedButtons() {
        paymentProceedBtns.forEach(btn => {
            btn.disabled = false;
            btn.classList.remove('disabled');
            btn.removeAttribute('aria-disabled');
        });
    }

    function clearActive() {
        modal.querySelectorAll('.payment-method-active').forEach(el => el.classList.remove('payment-method-active'));
    }

    function hideWalletInfo() {
        walletInfos.forEach(walletInfo => {
            walletInfo.classList.add('d-none');
            walletInfo.classList.remove('is-active');
        });
    }

    function showWalletInfo() {
        walletInfos.forEach(walletInfo => {
            walletInfo.classList.remove('d-none');
            walletInfo.classList.add('is-active');
        });
    }

    function hideDigitalInfo() {
        digitalInfos.forEach(el => el.classList.remove('is-active'));
    }

    function showDigitalInfo() {
        digitalInfos.forEach(el => el.classList.add('is-active'));
    }


    function setPaymentMethod(value, form) {
        const radios = form.querySelectorAll('input[name="payment_method"][type="radio"]');
        radios.forEach(r => r.checked = false);
        let hidden = form.querySelector('input[name="payment_method"][type="hidden"]');
        const radio = form.querySelector(`input[name="payment_method"][value="${value}"][type="radio"]`);
        if (radio) {
            radio.checked = true;
            hidden?.remove();
        } else {
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'payment_method';
                form.appendChild(hidden);
            }
            hidden.value = value;
        }
    }
    paymentProceedBtns.forEach(btn => {
        btn.addEventListener('click', function (e) {
            let isChecked = false;
            paymentRadios.forEach(radio => {
                if (radio.checked) {
                    isChecked = true;
                }
            });
            if (paymentMethodInput && paymentMethodInput.checked) {
                isChecked = true;
            }
            if (!isChecked) {
                e.preventDefault();
                toastr.error("Please Select Payment method");
                return false;
            }
        });
    });
    codCards.forEach(codCard => {
        codCard.addEventListener('click', function () {
            clearActive();
            this.classList.add('payment-method-active');
            hideWalletInfo();
            enableProceedButtons();
            hideDigitalInfo();
            bringChangeSections.forEach(section => {
                section.style.display = 'block';
            });
            payOfflines.forEach(payOffline => {
                payOffline.checked = false;
            });
            const form = this.closest('form');
            if (form) setPaymentMethod('cash_on_delivery', form);
        });
    });

    walletCards.forEach(card => {
        card.addEventListener('click', function (e) {
            if (this.classList.contains('wallet-disabled')) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            clearActive();
            enableProceedButtons();
            hideDigitalInfo();
            this.classList.add('payment-method-active');
            bringChangeSections.forEach(section => {
                section.style.display = 'none';
            });
            payOfflines.forEach(payOffline => {
                payOffline.checked = false;
            });
            showWalletInfo();
            const form = this.closest('form');
            if (form) setPaymentMethod('wallet', form);
        });
    });

    paymentRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            if (!this.checked) return;
            clearActive();
            hideWalletInfo();
            enableProceedButtons();
            bringChangeSections.forEach(section => {
                section.style.display = 'none';
            });
            payOfflines.forEach(payOffline => {
                payOffline.checked = false;
            });

            const form = this.closest('form');
            if (form) setPaymentMethod(this.value, form);
        });
    });

    payOfflines.forEach(payOffline => {
        payOffline.addEventListener('click', function () {
            if (!this.checked) return;

            const editDueAmount = this.dataset.editDueAmount;
            const orderId = this.dataset.orderId;
            const methodId = this.dataset.methodId;

            clearActive();
            hideWalletInfo();
            disableProceedButtons();
            hideDigitalInfo();
            bringChangeSections.forEach(section => section.style.display = 'none');

            if (this.dataset.theme === "aster") {
                showGlobalLoader();
                pay_offline_method_field(methodId, editDueAmount, true, orderId);
                const form = this.closest('form');
                const hidden = form?.querySelector('input[name="payment_method"][type="hidden"]');
                hidden?.remove();
                const card = this.closest('.bg-white').querySelector('.pay_offline_card');
                if (orderDetailsSection) orderDetailsSection.classList.add('d-none');
                if (paymentMethodSection) paymentMethodSection.classList.add('d-none');
                if (offlinePaymentSection) offlinePaymentSection.classList.remove('d-none');
                if (codDigitalSection) codDigitalSection.classList.add('d-none');

                codSections.forEach(section => {
                    section.classList.add('d-none');
                });
                offlineSections.forEach(section => {
                    section.classList.remove('d-none');
                });
            } else {
                payOfflineCards.forEach(card => card.classList.remove('d-none'));
            }
        });
    });


    offlineButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const methodId = this.id;
            const editDueAmount = this.dataset.editDueAmount;
            const orderId = this.dataset.orderId;
            showGlobalLoader();
            const selectInModal = modal.querySelector('.pay_offline_method');
            if (selectInModal) {
                selectInModal.value = methodId;
            }

            pay_offline_method_field(methodId, editDueAmount, false, orderId);

            if (orderDetailsSection) orderDetailsSection.classList.add('d-none');
            if (paymentMethodSection) paymentMethodSection.classList.add('d-none');
            if (offlinePaymentSection) offlinePaymentSection.classList.remove('d-none');
            if (codDigitalSection) codDigitalSection.classList.add('d-none');

            codSections.forEach(section => {
                section.classList.add('d-none');
            });
            offlineSections.forEach(section => {
                section.classList.remove('d-none');
            });
        });
    });

    backToCODs.forEach(backBtn => {
        backBtn.addEventListener('click', function () {
            codSections.forEach(section => {
                section.classList.remove('d-none');
            });
            offlineSections.forEach(section => {
                section.classList.add('d-none');
            });
            if (this.dataset.theme == "aster") {
                payOfflines.forEach(payOffline => {
                    payOffline.checked = false;
                });
                payOfflineCards.forEach(card => card.classList.add('d-none'));
            }
        });
    });

    digitalPaymentCards.forEach(card => {
        card.addEventListener('click', function (e) {
            clearActive();
            disableProceedButtons();
            hideWalletInfo();
            this.classList.add('payment-method-active');
            bringChangeSections.forEach(section => {
                section.style.display = 'none';
            });
            payOfflines.forEach(payOffline => {
                payOffline.checked = false;
            });
            showDigitalInfo();
            const form = this.closest('form');
            if (form) setPaymentMethod('digital_payment', form);
        });
    });

    document.querySelectorAll('.digital-info-section .payment-radio').forEach(radio => {
        radio.addEventListener('change', function () {
            if (!this.checked) return;

            enableProceedButtons();

            document.querySelectorAll('.payment-method:not(.pay-via-digital)')
                .forEach(el => el.classList.remove('border-selected'));

            const paymentMethod = this.closest('label')?.querySelector('.payment-method');
            if (paymentMethod) {
                paymentMethod.classList.add('border-selected');
            }

            const form = this.closest('form');
            if (form) {
                setPaymentMethod(this.value, form);
            }
        });
    });


    const defaultCodCard = modal.querySelector('.cod-for-cart.payment-method-active');
    if (defaultCodCard) {
        hideWalletInfo();
        bringChangeSections.forEach(section => {
            section.style.display = 'block';
        });
        enableProceedButtons();
    }
}

function showGlobalLoader() {
    const loaders = $(".global-loader");
    if (loaders.length) {
        loaders.each(function () {
            $(this).removeClass("d-none").fadeIn(150);
        });
    } else {
        console.warn("showGlobalLoader - .global-loader elements not found!");
    }
}

function hideGlobalLoader() {
    const loaders = $(".global-loader");
    if (loaders.length) {
        loaders.each(function () {
            $(this).fadeOut(150, function () {
                $(this).addClass("d-none");
            });
        });
    } else {
        console.warn("hideGlobalLoader - .global-loader elements not found!");
    }
}

$(".order-choose-payment-method-modal").each(function () {
    const $modal = $(this);

    $modal.find(".payment-method_parent").on("click", function (e) {
        e.preventDefault();

        $modal.find(".payment-method_parent").removeClass("border-selected");

        $(this).addClass("border-selected");

        const isDigitalActive = $modal.find("#digital-payment-btn .payment-method_parent")
            .hasClass("border-selected");

        if (isDigitalActive) {
            $modal.find(".digital-payment-card").removeClass("border-selected");

            $(this).addClass("border-selected");
        } else {
            $modal.find(".digital-payment-card").removeClass("border-selected");

            $modal.find(".digital-payment").hide("slow", function () {
                if (typeof updateDigitalPaymentBg === "function") {
                    updateDigitalPaymentBg();
                }
            });
        }
    });
});
