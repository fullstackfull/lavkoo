{{--
    Keeps the Saudi address fields in charge of the place of an address on the website's address forms.

    Parameters:
      $forms  list of ['form' => selector, 'country' => selector, 'street' => selector, 'phone' => selector], selectors read inside the form

    While the form's country is Saudi Arabia:
      - the map picker only chooses the coordinates: what it writes into the street textarea is dropped (the customer types the street),
      - the phone is read as a Saudi number unless it carries its own country code, which is the only number the server accepts for a
        Saudi address (a saved address says which number it was saved with in the form's data-saved-phone).
    Other countries behave as before. Include it after the page's own scripts and after the form markup.
--}}
@use('App\Services\Address\AddressCountry')
@php($saudiValues = ['sa', 'ksa', mb_strtolower(AddressCountry::LEGACY_NAME), 'السعودية', 'المملكة العربية السعودية'])
<script>
    (function () {
        'use strict';

        var SAUDI = @json($saudiValues);
        var configs = @json($forms);
        var permitted = false;

        function isSaudi(country) {
            return !!country && SAUDI.indexOf(String(country.value).trim().toLowerCase()) !== -1;
        }

        function protectStreet(street, country) {
            var descriptor = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
            Object.defineProperty(street, 'value', {
                configurable: true,
                get: function () { return descriptor.get.call(this); },
                set: function (value) {
                    if (permitted || !isSaudi(country)) { descriptor.set.call(this, value); }
                },
            });
        }

        function hasOwnPrefix(number) {
            return number.charAt(0) === '+' || number.slice(0, 2) === '00';
        }

        function startOnSaudiFlag(form, phone, country) {
            function apply() {
                var instance = window.intlTelInputGlobals ? window.intlTelInputGlobals.getInstance(phone) : null;
                var saved = form.getAttribute('data-saved-phone');
                var number = (saved !== null ? saved : phone.value).trim();
                if (instance && isSaudi(country) && !hasOwnPrefix(number)) { instance.setCountry('sa'); }
            }
            country.addEventListener('change', apply);
            phone.addEventListener('input', function () { form.removeAttribute('data-saved-phone'); });
            apply();
            window.addEventListener('load', apply);
        }

        function eachForm(callback) {
            configs.forEach(function (config) {
                var form = document.querySelector(config.form);
                var country = form ? form.querySelector(config.country) : null;
                if (country) { callback(form, country, config); }
            });
        }

        window.storefrontAddress = {
            permitStreetWrites: function (callback) {
                permitted = true;
                try { callback(); } finally { permitted = false; }
            },
        };

        eachForm(function (form, country, config) {
            var street = form.querySelector(config.street);
            if (street) { protectStreet(street, country); }
        });

        function startPhones() {
            eachForm(function (form, country, config) {
                var phone = form.querySelector(config.phone);
                if (phone) { startOnSaudiFlag(form, phone, country); }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startPhones);
        } else {
            startPhones();
        }
    })();
</script>
