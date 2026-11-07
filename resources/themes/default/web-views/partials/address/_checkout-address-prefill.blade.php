{{--
    Fills the Saudi address fields when a saved address is chosen on the checkout page.

    The page's own script (public/assets/front-end/js/shipping.js) fills the old fields from the saved address. This wraps the two
    functions it uses so the region, city, district and National Address of the saved address are filled as well, and an address that
    still has to be completed says so and has its "update this address" box ticked, so completing it also completes the saved one.

    Parameters:
      $forms  list of ['form' => selector, 'country' => selector, 'select' => name of the page's function, 'updateBox' => selector]

    Include it after shipping.js and after the form markup, on a page that also has _unified-address-script.
--}}
<script>
    (function () {
        'use strict';

        var configs = @json($forms);

        function parse(json) {
            try { return JSON.parse(json) || {}; } catch (error) { return {}; }
        }

        function setValues(block, saved) {
            var cityInput = block.querySelector('[data-ua-input]');
            block.querySelector('[data-ua-region]').value = saved.region_id ? String(saved.region_id) : '';
            block.querySelector('[data-ua-city-id]').value = saved.city_id ? String(saved.city_id) : '';
            cityInput.value = (block.getAttribute('data-locale') === 'en' ? saved.city_name_en : saved.city_name_ar) || '';
            cityInput.setAttribute('data-name-ar', saved.city_name_ar || '');
            cityInput.setAttribute('data-name-en', saved.city_name_en || '');
            block.querySelector('[name$="district"]').value = saved.district || '';
            block.querySelector('[data-ua-national]').value = saved.national_address || '';
        }

        function refill(form, pristine, saved) {
            var current = form.querySelector('[data-unified-address]');
            var fresh = pristine.cloneNode(true);
            if (saved.city_id) { setValues(fresh, saved); }
            current.parentNode.replaceChild(fresh, current);
            window.unifiedAddress.init(form);
        }

        configs.forEach(function (config) {
            var form = document.querySelector(config.form);
            var block = form ? form.querySelector('[data-unified-address]') : null;
            var original = window[config.select];
            if (!block || typeof original !== 'function') { return; }

            var pristine = block.cloneNode(true);
            var notice = form.querySelector('[data-ua-complete-notice]');

            window[config.select] = function (json) {
                var saved = parse(json);
                var incomplete = saved.needs_completion === true;
                var country = form.querySelector(config.country);
                var update;

                form.setAttribute('data-saved-phone', saved.phone == null ? '' : String(saved.phone));
                window.storefrontAddress.permitStreetWrites(function () { original(json); });
                refill(form, pristine, saved);
                if (country) { country.dispatchEvent(new Event('change', {bubbles: true})); }
                if (notice) { notice.hidden = !incomplete; }
                update = form.querySelector(config.updateBox);
                if (update && incomplete) { update.checked = true; }
            };
        });
    })();
</script>
