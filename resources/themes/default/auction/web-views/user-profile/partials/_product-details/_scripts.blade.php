<script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/auction-product-details.js') }}"></script>

<script>
    $(document).on('change', '#myBidClaimedOnly', function () {
        var $container = $('#claimed-bid-list-container');
        var $insightContainer = $('#claimed-insight-container');
        var baseUrl = $container.data('url');
        var errorMessage = $container.data('error-message');
        var myBidsOnly = this.checked;

        var minHeight = $container.height();
        if (minHeight > 0) {
            $container.css('min-height', minHeight + 'px');
        }
        $container.stop(true, false).animate({ opacity: 0 }, 150, function () {
            $container.html('<div class="text-center py-4"><span class="spinner-border spinner-border-sm"></span></div>').animate({ opacity: 1 }, 150);
        });
        if ($insightContainer.length) {
            $insightContainer.stop(true, false).animate({ opacity: 0 }, 150);
        }

        $.ajax({
            url: baseUrl,
            type: 'GET',
            data: myBidsOnly ? { my_bids_only: 1 } : {},
            success: function (data) {
                $container.stop(true, false).hide().css('opacity', 1).html(data.html).fadeIn(500, function () {
                    $container.css('min-height', '');
                });
                if ($insightContainer.length && data.insightHtml) {
                    $insightContainer.stop(true, false).hide().css('opacity', 1).html(data.insightHtml).fadeIn(500, function () {
                        $insightContainer.css('min-height', '');
                    });
                    $insightContainer.find('[data-bs-toggle="tooltip"]').each(function () {
                        new bootstrap.Tooltip(this);
                    });
                }
            },
            error: function () {
                $container.stop(true, false).hide().css('opacity', 1).html('<div class="text-center py-4 text-danger">' + errorMessage + '</div>').fadeIn(500, function () {
                    $container.css('min-height', '');
                });
                if ($insightContainer.length) {
                    $insightContainer.stop(true, false).animate({ opacity: 1 }, 500, function () {
                        $insightContainer.css('min-height', '');
                    });
                }
            }
        });
    });
</script>

<script>
    (function () {
        // Owner-perspective Delivery Status dropdown — POSTs the chosen status
        // to auction.delivery-status.update on change, then surfaces a toast and
        // reloads so the rest of the page (header badge, sidebar timeline, etc.)
        // reflects the new state.
        const $select = $('.js-owner-delivery-status');
        if (!$select.length) {
            return;
        }

        function notify(type, message) {
            if (typeof toastMagic !== 'undefined' && typeof toastMagic[type] === 'function') {
                toastMagic[type](message);
                return;
            }
            if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
                return;
            }
            window.alert(message);
        }

        // We don't initialise current-status from $select.val() here — when the
        // only `selected` option is also `disabled` (the initial purchase_complete
        // state), browsers return '' from select.value and we'd cache the wrong
        // baseline, breaking the revert path. Instead we read it lazily from the
        // server-rendered `data-current-status` attribute on first access.

        // Force-select an option even if it's disabled. Browsers reject
        // programmatic selection of a disabled <option> (both `.val()` and
        // `option.selected = true` get ignored), which is exactly what
        // happens when we revert to the locked "Purchase Complete" option
        // after the user clicks "No". Toggling the `disabled` attribute
        // around `selectedIndex` works universally.
        function revertTo($el, value) {
            const select = $el[0];
            if (!select) return;
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === value) {
                    const option = select.options[i];
                    const wasDisabled = option.disabled;
                    option.disabled = false;
                    select.selectedIndex = i;
                    option.disabled = wasDisabled;
                    return;
                }
            }
            $el.val(value);
        }

        function postUpdate($el, newStatus, previousStatus) {
            $el.prop('disabled', true);

            $.ajax({
                url: $el.data('update-url'),
                type: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
                },
                data: {
                    auction_product_id: $el.data('auction-product-id'),
                    delivery_status: newStatus
                }
            }).done(function (response) {
                notify('success', response.message || '{{ translate('delivery_status_updated_successfully') }}');
                setTimeout(function () { window.location.reload(); }, 1000);
            }).fail(function (xhr) {
                const msg = xhr?.responseJSON?.message || '{{ translate('something_went_wrong') }}';
                notify('error', msg);
                revertTo($el, previousStatus);
                $el.data('current-status', previousStatus);
                $el.prop('disabled', false);
            });
        }

        $select.on('change', function () {
            const $el = $(this);
            const newStatus = $el.val();
            const previousStatus = $el.data('current-status') || '';

            if (!newStatus || newStatus === previousStatus) {
                return;
            }

            // Match the system-wide confirmation pattern (see route_alert in
            // resources/themes/default/layouts/front-end/app.blade.php) — same
            // copy, button colors and reverseButtons orientation so the dialog
            // feels native to the rest of the storefront.
            Swal.fire({
                title: '{{ translate('are_you_sure') }}?',
                text: '{{ translate('Do you want to update the delivery status') }}?',
                type: 'warning',
                icon: 'warning',
                showCancelButton: true,
                // Bootstrap "secondary" gray. The system-wide pattern passes
                // `'default'` here, which isn't a valid CSS color and leaves the
                // cancel button without a visible background — give it a real
                // value so the dialog looks correct on this page.
                cancelButtonColor: '#6c757d',
                confirmButtonColor: '{{ $web_config['primary_color'] ?? '#FF8C00' }}',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('yes') }}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    postUpdate($el, newStatus, previousStatus);
                } else {
                    // User cancelled — restore the dropdown to its previous value.
                    revertTo($el, previousStatus);
                }
            });
        });
    })();
</script>

<script>
    (function () {
        const $select = $('.js-owner-payment-status');
        if (!$select.length) return;

        function notify(type, message) {
            if (typeof toastMagic !== 'undefined' && typeof toastMagic[type] === 'function') {
                toastMagic[type](message);
                return;
            }
            if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
                return;
            }
            window.alert(message);
        }

        function revertTo($el, value) {
            const select = $el[0];
            if (!select) return;
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === value) {
                    select.selectedIndex = i;
                    return;
                }
            }
            $el.val(value);
        }

        function postUpdate($el, newStatus, previousStatus) {
            $el.prop('disabled', true);

            $.ajax({
                url: $el.data('update-url'),
                type: 'POST',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content') },
                data: {
                    auction_product_id: $el.data('auction-product-id'),
                    payment_status: newStatus
                }
            }).done(function (response) {
                notify('success', response.message || '{{ translate('payment_status_updated_successfully') }}');
                setTimeout(function () { window.location.reload(); }, 1000);
            }).fail(function (xhr) {
                const msg = xhr?.responseJSON?.message || '{{ translate('something_went_wrong') }}';
                notify('error', msg);
                revertTo($el, previousStatus);
                $el.prop('disabled', false);
            });
        }

        $select.on('change', function () {
            const $el = $(this);
            const newStatus = $el.val();
            const previousStatus = $el.data('current-status') || '';

            if (!newStatus || newStatus === previousStatus) return;

            Swal.fire({
                title: '{{ translate('are_you_sure') }}?',
                text: '{{ translate('Do you want to update the payment status') }}?',
                type: 'warning',
                icon: 'warning',
                showCancelButton: true,
                cancelButtonColor: '#6c757d',
                confirmButtonColor: '{{ $web_config['primary_color'] ?? '#FF8C00' }}',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('yes') }}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    postUpdate($el, newStatus, previousStatus);
                } else {
                    revertTo($el, previousStatus);
                }
            });
        });
    })();
</script>

<script>
    (function () {
        const $select = $('.js-owner-product-status');
        if (!$select.length) return;

        function notify(type, message) {
            if (typeof toastMagic !== 'undefined' && typeof toastMagic[type] === 'function') {
                toastMagic[type](message);
                return;
            }
            if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
                return;
            }
            window.alert(message);
        }

        function revertTo($el, value) {
            $el.prop('checked', String(value) === String($el.data('active-value')));
        }

        function postUpdate($el, newStatus, previousStatus) {
            $el.prop('disabled', true);

            $.ajax({
                url: $el.data('update-url'),
                type: 'POST',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content') },
                data: {
                    auction_product_id: $el.data('auction-product-id'),
                    status: newStatus
                }
            }).done(function (response) {
                notify('success', response.message || '{{ translate('auction_status_updated_successfully') }}');
                setTimeout(function () { window.location.reload(); }, 1000);
            }).fail(function (xhr) {
                const msg = xhr?.responseJSON?.message || '{{ translate('something_went_wrong') }}';
                notify('error', msg);
                revertTo($el, previousStatus);
                $el.data('current-status', previousStatus);
                $el.prop('disabled', false);
            });
        }

        $select.on('change', function () {
            const $el = $(this);
            const newStatus = String($el.is(':checked') ? $el.data('active-value') : $el.data('inactive-value'));
            const previousStatus = String($el.data('current-status'));

            if (newStatus === previousStatus) return;

            Swal.fire({
                title: '{{ translate('are_you_sure') }}?',
                text: '{{ translate('Do you want to update the auction status') }}?',
                type: 'warning',
                icon: 'warning',
                showCancelButton: true,
                cancelButtonColor: '#6c757d',
                confirmButtonColor: '{{ $web_config['primary_color'] ?? '#FF8C00' }}',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('yes') }}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    postUpdate($el, newStatus, previousStatus);
                } else {
                    revertTo($el, previousStatus);
                }
            });
        });
    })();
</script>

<script>
    (function () {
        const $form       = $('#auction-withdraw-form');
        const offcanvasEl = document.getElementById('auction-customer-withdraw-offcanvas');
        if (!$form.length) return;

        function notify(type, message) {
            if (typeof toastMagic !== 'undefined' && typeof toastMagic[type] === 'function') {
                toastMagic[type](message);
            } else if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
            } else {
                window.alert(message);
            }
        }

        // Strip non-digit characters from numeric method fields (account no, phone, etc.)
        $form.on('input', '[data-numeric-field]', function () {
            const $el     = $(this);
            const cleaned = $el.val().replace(/\D/g, '');
            if ($el.val() !== cleaned) $el.val(cleaned);
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            const $btn = $form.find('[type="submit"]');
            $btn.prop('disabled', true);

            $.post({
                url: $form.attr('action'),
                data: $form.serialize(),
                success: function (res) {
                    if (res.status) {
                        notify('success', res.message);
                        if (offcanvasEl) {
                            bootstrap.Offcanvas.getInstance(offcanvasEl)?.hide();
                        }
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else {
                        notify('error', res.message);
                        $btn.prop('disabled', false);
                    }
                },
                error: function (xhr) {
                    const msg = xhr?.responseJSON?.message || '{{ translate('something_went_wrong') }}';
                    notify('error', msg);
                    $btn.prop('disabled', false);
                },
            });
        });
    })();
</script>

<script>
    (function () {
        const $select = $('#auctionWithdrawMethodSelect');
        const $fields = $('#auction-withdraw-dynamic-fields');

        if (!$select.length) return;

        $select.on('change', function () {
            const methodId = $(this).val();
            // Clear any stale validation errors from the previous method's fields
            $fields.find('.is-invalid').removeClass('is-invalid');
            $fields.find('.js-field-error').remove();

            if (!methodId) { $fields.empty(); return; }

            $.post({
                url: $(this).data('route'),
                data: { method_id: methodId, _token: $('meta[name="_token"]').attr('content') },
                success: function (res) { $fields.html(res.html); },
            });
        });
    })();
</script>

<script>
    (function () {
        if (typeof Plyr === 'undefined') return;
        document.querySelectorAll('.js-auction-product-player').forEach(function (el) {
            new Plyr(el, {
                iconUrl: '{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.svg') }}'
            });
        });
    })();
</script>

<script>
    (function () {
        // See Less / See More toggle for offline payment details card
        document.querySelectorAll('.js-offline-payment-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const wrap = this.closest('.card-body')?.querySelector('.offline-payment-details-wrap');
                if (!wrap) return;
                const isHidden = wrap.classList.toggle('d-none');
                this.textContent = isHidden
                    ? (this.dataset.more || '{{ translate('See More') }}')
                    : (this.dataset.less || '{{ translate('See Less') }}');
            });
        });

        // Edit offline payment — load method fields, pre-fill existing values, open modal
        function setOfflineFieldValue(wrap, name, value) {
            if (value === null || value === undefined) return false;
            const field = wrap.querySelector('[name="' + name + '"]');
            if (!field) return false;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        function prefillOfflinePayment(wrap, paymentInfo) {
            Object.entries(paymentInfo).forEach(function ([key, value]) {
                // Hidden method_id / method_name are already rendered server-side.
                if (key === 'method_id' || key === 'method_name') return;

                // Dynamic fields submitted as method_informations[<input>].
                if (key === 'method_informations' && value && typeof value === 'object') {
                    Object.entries(value).forEach(function ([fieldKey, fieldValue]) {
                        setOfflineFieldValue(wrap, 'method_informations[' + fieldKey + ']', fieldValue);
                    });
                    return;
                }

                // Payment note submitted as offline_payment[payment_note].
                if (key === 'payment_note') {
                    setOfflineFieldValue(wrap, 'offline_payment[payment_note]', value);
                    return;
                }

                // Fallback: nested objects → bracket notation, scalars → direct or offline_payment[].
                if (value && typeof value === 'object') {
                    Object.entries(value).forEach(function ([fieldKey, fieldValue]) {
                        setOfflineFieldValue(wrap, key + '[' + fieldKey + ']', fieldValue);
                    });
                } else if (!setOfflineFieldValue(wrap, key, value)) {
                    setOfflineFieldValue(wrap, 'offline_payment[' + key + ']', value);
                }
            });
        }

        document.querySelectorAll('.js-edit-offline-payment-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const methodId    = this.dataset.methodId;
                const amount      = this.dataset.amount;
                const routeUrl    = document.getElementById('route-pay-offline-method-list')?.dataset?.url;
                const offlineModal = document.getElementById('modal-auction-offline-payment');
                const fieldWrap   = document.getElementById('auction_offline_payment_field');
                const goBackBtn   = document.getElementById('auction-offline-go-back');
                if (!routeUrl || !methodId || !offlineModal || !fieldWrap) return;

                // Opened from My Submitted Info → Edit: no previous step, hide Go Back.
                if (goBackBtn) goBackBtn.classList.add('d-none');

                let paymentInfo = {};
                try { paymentInfo = JSON.parse(this.dataset.paymentInfo || '{}'); } catch (e) {}

                fieldWrap.innerHTML = '';
                $.ajax({
                    url: routeUrl + '?method_id=' + methodId + '&edit_due_amount=' + encodeURIComponent(amount || 0),
                    type: 'GET',
                    success: function (response) {
                        fieldWrap.innerHTML = response?.methodHtml || '';
                        prefillOfflinePayment(fieldWrap, paymentInfo);
                        new bootstrap.Modal(offlineModal).show();
                    }
                });
            });
        });
    })();
</script>
