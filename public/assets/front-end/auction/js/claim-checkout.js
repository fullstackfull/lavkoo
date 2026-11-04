document.addEventListener('DOMContentLoaded', function () {
    // ===== Existing form logic =====
    const form = document.getElementById('auction-claim-form');
    const sameAsBillingCheckbox = document.getElementById('sameAsBilling');
    const billingFields = document.getElementById('billing-fields');

    if (sameAsBillingCheckbox && billingFields) {
        sameAsBillingCheckbox.addEventListener('change', function () {
            billingFields.style.display = this.checked ? 'none' : 'block';
            var sameFlag = document.getElementById('billing_same_as_shipping_input');
            if (sameFlag) sameFlag.value = this.checked ? '1' : '0';
        });
    }

    // --- Saved address: populate shipping fields on select, restore on deselect ---
    const savedAddressSelect = document.getElementById('claim-saved-address-select');
    if (savedAddressSelect) {
        const shippingFieldNames = ['contact_person_name', 'phone', 'address', 'city', 'zip', 'country'];
        const originalShippingValues = {};
        shippingFieldNames.forEach(function (fieldName) {
            const el = form.querySelector('[name="shipping_address_info[' + fieldName + ']"]');
            originalShippingValues[fieldName] = el ? el.value : '';
        });

        const shippingAddressIdInput = document.getElementById('shipping_address_id_input');

        savedAddressSelect.addEventListener('change', function () {
            const setVal = function (fieldName, val) {
                const el = form.querySelector('[name="shipping_address_info[' + fieldName + ']"]');
                if (el) el.value = val !== undefined && val !== null ? val : '';
            };

            if (!this.value) {
                shippingFieldNames.forEach(function (fieldName) {
                    setVal(fieldName, originalShippingValues[fieldName]);
                });
                if (shippingAddressIdInput) shippingAddressIdInput.value = '';
                return;
            }

            const addr = JSON.parse(this.value);
            setVal('contact_person_name', addr.contact_person_name);
            setVal('phone', addr.phone);
            setVal('address', addr.address);
            setVal('city', addr.city);
            setVal('zip', addr.zip);
            setVal('country', addr.country);
            if (shippingAddressIdInput) shippingAddressIdInput.value = addr.id || '';
        });

        // Clear the saved address ID if the user manually edits any shipping field
        shippingFieldNames.forEach(function (fieldName) {
            const el = form.querySelector('[name="shipping_address_info[' + fieldName + ']"]');
            if (el) {
                el.addEventListener('input', function () {
                    if (shippingAddressIdInput && shippingAddressIdInput.value) {
                        shippingAddressIdInput.value = '';
                        savedAddressSelect.value = '';
                    }
                });
            }
        });
    }

    // --- Saved billing address: populate billing fields on select ---
    const savedBillingAddressSelect = document.getElementById('claim-saved-billing-address-select');
    if (savedBillingAddressSelect) {
        const billingFieldNames = ['contact_person_name', 'phone', 'address', 'city', 'zip', 'country'];
        const billingAddressIdInput = document.getElementById('billing_address_id_input');

        savedBillingAddressSelect.addEventListener('change', function () {
            const setVal = function (fieldName, val) {
                const el = form.querySelector('[name="billing_address_info[' + fieldName + ']"]');
                if (el) el.value = val !== undefined && val !== null ? val : '';
            };

            if (!this.value) {
                billingFieldNames.forEach(function (fieldName) { setVal(fieldName, ''); });
                if (billingAddressIdInput) billingAddressIdInput.value = '';
                return;
            }

            const addr = JSON.parse(this.value);
            setVal('contact_person_name', addr.contact_person_name);
            setVal('phone', addr.phone);
            setVal('address', addr.address);
            setVal('city', addr.city);
            setVal('zip', addr.zip);
            setVal('country', addr.country);
            if (billingAddressIdInput) billingAddressIdInput.value = addr.id || '';
        });

        billingFieldNames.forEach(function (fieldName) {
            const el = form.querySelector('[name="billing_address_info[' + fieldName + ']"]');
            if (el) {
                el.addEventListener('input', function () {
                    if (billingAddressIdInput && billingAddressIdInput.value) {
                        billingAddressIdInput.value = '';
                        savedBillingAddressSelect.value = '';
                    }
                });
            }
        });
    }

    function copyShippingToBilling() {
        const fields = ['contact_person_name', 'phone', 'country', 'city', 'zip', 'address'];
        fields.forEach(function (key) {
            const src = form.querySelector('[name="shipping_address_info[' + key + ']"]');
            const dst = form.querySelector('[name="billing_address_info[' + key + ']"]');
            if (src && dst) dst.value = src.value;
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const injectedPm = form.querySelector('input[type="hidden"][name="payment_method"][data-payment-injected]');
            if (!injectedPm) {
                if (typeof toastr !== 'undefined') {
                    toastr.error(document.getElementById('claim-msg-select-payment')?.dataset.message || '');
                }
                return;
            }

            if (sameAsBillingCheckbox && sameAsBillingCheckbox.checked && billingFields) {
                copyShippingToBilling();
                const billingIdInput = document.getElementById('billing_address_id_input');
                if (billingIdInput) billingIdInput.value = '';
                const shippingIdInput = form.querySelector('[name="shipping_address_id"]');
                if (shippingIdInput && shippingIdInput.value) {
                    if (billingIdInput) {
                        billingIdInput.value = shippingIdInput.value;
                    } else {
                        var existingBillingId = document.createElement('input');
                        existingBillingId.type = 'hidden';
                        existingBillingId.name = 'billing_address_id';
                        existingBillingId.value = shippingIdInput.value;
                        form.appendChild(existingBillingId);
                    }
                }
            }

            const submitBtn = form.querySelector('[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            $.ajax({
                url: form.action,
                type: 'POST',
                data: new FormData(form),
                processData: false,
                contentType: false,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                success: function (response) {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                        return;
                    }
                    if (response.success && typeof toastr !== 'undefined') {
                        toastr.success(response.message || '');
                    }
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    }
                },
                error: function (xhr) {
                    if (submitBtn) submitBtn.disabled = false;
                    var msg = '';
                    try { msg = JSON.parse(xhr.responseText).message; } catch (e) {}
                    if (typeof toastr !== 'undefined') {
                        toastr.error(msg || document.getElementById('claim-msg-something-went-wrong')?.dataset.message || '');
                    }
                },
            });
        });
    }

    // ===== Payment Method Modal Logic =====
    const claimPaymentModal    = document.getElementById('payment_method-choose');
    const claimOfflineModal    = document.getElementById('modal-claim-offline-payment');
    const claimAddBtn          = document.getElementById('claim-add-payment-btn');
    const claimChangeBtn       = document.getElementById('claim-change-payment-btn');
    const claimPaymentDisplay  = document.getElementById('claim-payment-display');
    const claimDisplayWalletRow    = document.getElementById('claim-display-wallet-row');
    const claimDisplayOtherRow     = document.getElementById('claim-display-other-row');
    const claimDisplayWalletAmount = document.getElementById('claim-display-wallet-amount');
    const claimDisplayOtherLabel   = document.getElementById('claim-display-other-label');
    const claimDisplayOtherAmount  = document.getElementById('claim-display-other-amount');
    const claimProcessBtn      = document.getElementById('claim-payment-process-btn');
    const applyWalletBtn       = document.getElementById('claim-apply-wallet-btn');
    const removeWalletBtn      = document.getElementById('claim-remove-wallet-btn');
    const walletBalanceSection = document.getElementById('claim-wallet-balance-section');
    const walletAppliedSection = document.getElementById('claim-wallet-applied-section');
    const paidByWalletSection  = document.getElementById('claim-paid-by-wallet-section');
    const offlineRadio         = document.getElementById('claim-pay-offline-radio');
    const offlineMethodsCard   = document.querySelector('.claim-offline-methods-card');
    const offlineGoBack        = document.getElementById('claim-offline-go-back');
    const offlineConfirmBtn    = document.getElementById('claim-offline-confirm-btn');

    const walletAmountDisplay  = document.getElementById('claim-data-wallet-amount')?.dataset.value  || '';
    const totalPayableDisplay  = document.getElementById('claim-data-total-payable')?.dataset.value || '';

    let walletApplied             = false;
    let selectedOfflineMethodId   = null;
    let selectedOfflineMethodName = null;

    // --- Wallet: Apply ---
    if (applyWalletBtn) {
        applyWalletBtn.addEventListener('click', function () {
            walletApplied = true;
            walletBalanceSection.classList.add('d-none');
            walletAppliedSection.classList.remove('d-none');
            paidByWalletSection.classList.remove('d-none');
            if (claimProcessBtn) claimProcessBtn.disabled = false;
            // Single method: deselect any gateway/offline/COD radio and clear injected inputs
            document.querySelectorAll('.claim-gateway-radio, #claim-pay-offline-radio, .claim-cod-radio').forEach(function (r) { r.checked = false; });
            if (offlineMethodsCard) offlineMethodsCard.classList.add('d-none');
            form.querySelectorAll('[data-payment-injected]').forEach(function (el) { el.remove(); });
        });
    }

    // --- Wallet: Remove ---
    if (removeWalletBtn) {
        removeWalletBtn.addEventListener('click', function () {
            walletApplied = false;
            walletAppliedSection.classList.add('d-none');
            paidByWalletSection.classList.add('d-none');
            walletBalanceSection.classList.remove('d-none');
            const anyGateway = document.querySelector('.claim-gateway-radio:checked');
            if (claimProcessBtn) claimProcessBtn.disabled = !anyGateway;
        });
    }

    // --- Offline radio: show methods, disable Process (must pick a method via sub-modal) ---
    if (offlineRadio) {
        offlineRadio.addEventListener('change', function () {
            if (this.checked) {
                // Single method: clear wallet if applied
                if (walletApplied) {
                    walletApplied = false;
                    if (walletAppliedSection) walletAppliedSection.classList.add('d-none');
                    if (paidByWalletSection) paidByWalletSection.classList.add('d-none');
                    if (walletBalanceSection) walletBalanceSection.classList.remove('d-none');
                }
                if (offlineMethodsCard) offlineMethodsCard.classList.remove('d-none');
                if (claimProcessBtn) claimProcessBtn.disabled = true;
            }
        });
    }

    // --- Gateway radio: clear wallet, hide offline methods, enable Process ---
    document.querySelectorAll('.claim-gateway-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            // Single method: clear wallet if applied
            if (walletApplied) {
                walletApplied = false;
                if (walletAppliedSection) walletAppliedSection.classList.add('d-none');
                if (paidByWalletSection) paidByWalletSection.classList.add('d-none');
                if (walletBalanceSection) walletBalanceSection.classList.remove('d-none');
            }
            if (offlineMethodsCard) offlineMethodsCard.classList.add('d-none');
            if (claimProcessBtn) claimProcessBtn.disabled = false;
        });
    });

    // --- COD radio: clear wallet, enable Process ---
    document.querySelectorAll('.claim-cod-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (walletApplied) {
                walletApplied = false;
                if (walletAppliedSection) walletAppliedSection.classList.add('d-none');
                if (paidByWalletSection) paidByWalletSection.classList.add('d-none');
                if (walletBalanceSection) walletBalanceSection.classList.remove('d-none');
            }
            if (offlineMethodsCard) offlineMethodsCard.classList.add('d-none');
            if (claimProcessBtn) claimProcessBtn.disabled = false;
        });
    });

    // --- Offline method button: AJAX load fields → open offline sub-modal ---
    document.querySelectorAll('.claim-offline-method-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const methodId   = this.dataset.methodId;
            const methodName = this.dataset.methodName;
            const methodAmount = this.dataset.amount;
            const offlinePaymentField = document.getElementById('claim-offline-payment-field');
            const routeUrl = document.getElementById('route-pay-offline-method-list')?.dataset?.url;

            selectedOfflineMethodId   = methodId;
            selectedOfflineMethodName = methodName;

            if (offlinePaymentField) offlinePaymentField.innerHTML = '';

            if (routeUrl && methodId) {
                $.ajax({
                    url: routeUrl + '?method_id=' + methodId + '&edit_due_amount=' + encodeURIComponent(methodAmount),
                    type: 'GET',
                    success: function (response) {
                        if (offlinePaymentField) offlinePaymentField.innerHTML = response?.methodHtml || '';

                        const bsPaymentModal = bootstrap.Modal.getInstance(claimPaymentModal);
                        if (bsPaymentModal) {
                            claimPaymentModal.addEventListener('hidden.bs.modal', function openOffline() {
                                claimPaymentModal.removeEventListener('hidden.bs.modal', openOffline);
                                new bootstrap.Modal(claimOfflineModal).show();
                            }, { once: true });
                            bsPaymentModal.hide();
                        }
                    },
                    error: function () {
                        if (typeof toastr !== 'undefined') {
                            toastr.error(document.getElementById('claim-msg-something-went-wrong')?.dataset.message || '');
                        }
                    }
                });
            }
        });
    });

    // --- Offline sub-modal: Go Back (reopen payment modal) ---
    if (offlineGoBack) {
        offlineGoBack.addEventListener('click', function (e) {
            e.preventDefault();
            const bsOfflineModal = bootstrap.Modal.getInstance(claimOfflineModal);
            if (bsOfflineModal) {
                claimOfflineModal.addEventListener('hidden.bs.modal', function reopenPayment() {
                    claimOfflineModal.removeEventListener('hidden.bs.modal', reopenPayment);
                    new bootstrap.Modal(claimPaymentModal).show();
                }, { once: true });
                bsOfflineModal.hide();
            }
        });
    }

    // --- Offline sub-modal: Confirm Payment ---
    if (offlineConfirmBtn) {
        offlineConfirmBtn.addEventListener('click', function () {
            const offlineField = document.getElementById('claim-offline-payment-field');
            injectOfflineFieldsToMainForm(offlineField, selectedOfflineMethodId);
            showClaimPaymentSummary('offline', selectedOfflineMethodName, totalPayableDisplay);

            const bsOfflineModal = bootstrap.Modal.getInstance(claimOfflineModal);
            if (bsOfflineModal) bsOfflineModal.hide();
        });
    }

    // --- Modal Process button ---
    if (claimProcessBtn) {
        claimProcessBtn.addEventListener('click', function () {
            const selectedGateway = document.querySelector('.claim-gateway-radio:checked');
            const selectedCod     = document.querySelector('.claim-cod-radio:checked');
            const bsModal         = bootstrap.Modal.getInstance(claimPaymentModal);

            if (walletApplied && !selectedGateway && !selectedCod) {
                clearInjectedPaymentInputs();
                const hiddenWallet = document.createElement('input');
                hiddenWallet.type  = 'hidden';
                hiddenWallet.name  = 'payment_method';
                hiddenWallet.value = 'wallet';
                hiddenWallet.setAttribute('data-payment-injected', '1');
                form.appendChild(hiddenWallet);
                showClaimPaymentSummary('wallet', null, walletAmountDisplay);
                if (bsModal) bsModal.hide();

            } else if (selectedGateway) {
                // Gateway: inject payment_method into form, show summary, close modal
                // Backend gateway redirect happens when user clicks "Proceed to Claim"
                const gatewayKey   = selectedGateway.dataset.gatewayKey;
                const gatewayLabel = selectedGateway.dataset.gatewayLabel || gatewayKey;

                clearInjectedPaymentInputs();
                const hiddenPm = document.createElement('input');
                hiddenPm.type  = 'hidden';
                hiddenPm.name  = 'payment_method';
                hiddenPm.value = gatewayKey;
                hiddenPm.setAttribute('data-payment-injected', '1');
                form.appendChild(hiddenPm);

                showClaimPaymentSummary('gateway', gatewayLabel, totalPayableDisplay);
                if (bsModal) bsModal.hide();

            } else if (selectedCod) {
                // COD: inject payment_method=cash_on_delivery, show summary, close modal
                clearInjectedPaymentInputs();
                const hiddenCod = document.createElement('input');
                hiddenCod.type  = 'hidden';
                hiddenCod.name  = 'payment_method';
                hiddenCod.value = 'cash_on_delivery';
                hiddenCod.setAttribute('data-payment-injected', '1');
                form.appendChild(hiddenCod);

                showClaimPaymentSummary('cod', document.getElementById('claim-msg-cash-on-delivery')?.dataset.message || '', totalPayableDisplay);
                if (bsModal) bsModal.hide();
            }
            // If neither, button is disabled — no action
        });
    }

    // --- Reset modal choice state when re-opened via "Change" ---
    if (claimPaymentModal) {
        claimPaymentModal.addEventListener('show.bs.modal', function () {
            // Preserve wallet state but reset gateway/offline/COD radio selections
            document.querySelectorAll('.claim-gateway-radio, #claim-pay-offline-radio, .claim-cod-radio').forEach(function (r) {
                r.checked = false;
            });
            if (offlineMethodsCard) offlineMethodsCard.classList.add('d-none');
            // Re-evaluate Process button state
            if (claimProcessBtn) claimProcessBtn.disabled = !walletApplied;
        });
    }

    // --- Helpers ---

    function showClaimPaymentSummary(type, label, amount) {
        if (!claimPaymentDisplay) return;

        claimPaymentDisplay.classList.remove('d-none');
        if (claimAddBtn) claimAddBtn.classList.add('d-none');
        if (claimChangeBtn) claimChangeBtn.classList.remove('d-none');

        // Reset both rows first
        if (claimDisplayWalletRow) claimDisplayWalletRow.classList.add('d-none');
        if (claimDisplayOtherRow) claimDisplayOtherRow.classList.add('d-none');

        if (type === 'wallet') {
            if (claimDisplayWalletRow) claimDisplayWalletRow.classList.remove('d-none');
            if (claimDisplayWalletAmount) claimDisplayWalletAmount.textContent = amount;
        } else {
            // Offline or gateway: show wallet row if applied + the other method row
            if (walletApplied && claimDisplayWalletRow) {
                claimDisplayWalletRow.classList.remove('d-none');
                if (claimDisplayWalletAmount) claimDisplayWalletAmount.textContent = walletAmountDisplay;
            }
            if (claimDisplayOtherRow) claimDisplayOtherRow.classList.remove('d-none');
            if (claimDisplayOtherLabel) claimDisplayOtherLabel.textContent = label;
            if (claimDisplayOtherAmount) claimDisplayOtherAmount.textContent = amount;
        }
    }

    function clearInjectedPaymentInputs() {
        if (!form) return;
        form.querySelectorAll('[data-payment-injected], [data-offline-injected]').forEach(function (el) { el.remove(); });
    }

    function injectOfflineFieldsToMainForm(sourceDiv, methodId) {
        if (!sourceDiv || !form) return;

        form.querySelectorAll('[data-offline-injected], [data-payment-injected]').forEach(function (el) { el.remove(); });

        const hiddenPm = document.createElement('input');
        hiddenPm.type  = 'hidden';
        hiddenPm.name  = 'payment_method';
        hiddenPm.value = 'offline_payment';
        hiddenPm.setAttribute('data-payment-injected', '1');
        form.appendChild(hiddenPm);

        sourceDiv.querySelectorAll('input, textarea').forEach(function (input) {
            if (!input.name || !input.value) return;
            if (input.name === 'payment_method') return;
            const existing = form.querySelector('[name="' + CSS.escape(input.name) + '"]');
            if (existing && existing.tagName === 'TEXTAREA') {
                existing.value = input.value;
            } else {
                const hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = input.name;
                hidden.value = input.value;
                hidden.setAttribute('data-offline-injected', '1');
                form.appendChild(hidden);
            }
        });
    }
});
