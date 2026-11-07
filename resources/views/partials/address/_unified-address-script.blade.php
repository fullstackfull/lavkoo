@once
    <style>
        .unified-address [data-ua-combobox] { position: relative; }
        .unified-address .ua-list { position: absolute; inset-inline: 0; top: 100%; z-index: 1060; max-height: 16rem; overflow-y: auto; margin: .125rem 0 0; padding: 0; list-style: none; background: #fff; color: #212529; border: 1px solid #ced4da; border-radius: .25rem; box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15); }
        .unified-address .ua-list[hidden] { display: none; }
        .unified-address .ua-option { display: flex; justify-content: space-between; align-items: center; gap: .75rem; min-height: 44px; padding: .5rem .75rem; cursor: pointer; text-align: start; }
        .unified-address .ua-option[aria-selected="true"], .unified-address .ua-option:hover { background: #e9f2ff; }
        .unified-address .ua-option small, .unified-address .ua-status { color: #565e64; }
        .unified-address .ua-status { padding: .5rem .75rem; text-align: start; }
        .unified-address input[data-ua-input], .unified-address input[data-ua-region-input], .unified-address input[data-ua-district], .unified-address select[data-ua-region], .unified-address input[data-ua-national] { min-height: 44px; font-size: 16px; }
        .unified-address .form-text { color: #495057; font-size: 13px; }
        .unified-address .ua-sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .alert.ua-notice { color: #5c3d00; background-color: #fff3cd; border-color: #ffe69c; font-size: 14px; }
        .badge.ua-badge { color: #5c3d00; background-color: #fff3cd; border: 1px solid #ffe69c; font-size: 12px; font-weight: 600; }
        .unified-address input[data-ua-national] { direction: ltr; text-transform: uppercase; letter-spacing: .08em; }
        [dir="rtl"] .unified-address input[data-ua-national] { text-align: right; }
    </style>
    <script>
        (function () {
            'use strict';

            var DEBOUNCE_MS = 180;
            var PAGE_SIZE = 50;
            var FETCH_TIMEOUT_MS = 10000;
            var ENGLISH_ARTICLES = ['al', 'ar', 'as', 'ash', 'at', 'ath', 'ad', 'adh', 'an', 'az', 'el'];
            var KIND_WORDS = ['\u0645\u062f\u064a\u0646\u0647', '\u0645\u062d\u0627\u0641\u0638\u0647', '\u0642\u0631\u064a\u0647', '\u0647\u062c\u0631\u0647', '\u0645\u0631\u0643\u0632', '\u0645\u0646\u0637\u0642\u0647', 'city', 'town', 'village', 'governorate', 'province', 'region', 'district', 'of'];
            var LETTERS = {'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ى': 'ي', 'ئ': 'ي', 'ؤ': 'و', 'ة': 'ه', 'ی': 'ي', 'ک': 'ك', 'ھ': 'ه', 'ہ': 'ه', 'ە': 'ه'};

            function fold(text) {
                return String(text || '')
                    .toLowerCase()
                    .replace(/[\u0660-\u0669]/g, function (digit) { return String(digit.charCodeAt(0) - 1632); })
                    .replace(/[\u06F0-\u06F9]/g, function (digit) { return String(digit.charCodeAt(0) - 1776); })
                    .replace(/[\uFF10-\uFF19]/g, function (digit) { return String(digit.charCodeAt(0) - 65296); })
                    .replace(/[\u064b-\u065f\u0670\u0640]/g, '')
                    .replace(/[\u0623\u0625\u0622\u0671\u0649\u0626\u0624\u0629\u06CC\u06A9\u06BE\u06C1\u06D5]/g, function (letter) { return LETTERS[letter]; })
                    .replace(/[^\p{L}\p{N}]+/gu, '');
            }

            function normalizeNationalAddress(text) {
                return String(text || '')
                    .replace(/[٠-٩]/g, function (digit) { return String(digit.charCodeAt(0) - 1632); })
                    .replace(/[۰-۹]/g, function (digit) { return String(digit.charCodeAt(0) - 1776); })
                    .replace(/[\s\p{Z}\p{Pd}\p{Cf}_.]+/gu, '')
                    .toUpperCase();
            }

            function searchKey(text) {
                var words = String(text || '').trim().split(/[\s\-]+/).map(fold).filter(Boolean);
                var named = words.filter(function (word) { return KIND_WORDS.indexOf(word) === -1; });
                words = named.length ? named : words;
                if (words.length > 1 && ENGLISH_ARTICLES.indexOf(words[0]) !== -1) { words.shift(); }
                if (words.length) { words[0] = words[0].replace(/^\u0627\u0644(?=.{2,})/, ''); }
                return words.join('');
            }

            function sameName(item, typed) {
                var wanted = searchKey(typed);
                return wanted !== '' && (searchKey(item.name_ar) === wanted || searchKey(item.name_en) === wanted);
            }

            function matchesTyped(name, typed) {
                var wanted = [fold(typed), searchKey(typed)];
                var known = [fold(name), searchKey(name)];
                return wanted.some(function (candidate) {
                    return candidate !== '' && known.some(function (key) { return key.indexOf(candidate) !== -1; });
                });
            }

            function debounce(callback, wait) {
                var timer = null;
                var debounced = function () {
                    var args = arguments;
                    clearTimeout(timer);
                    timer = setTimeout(function () { callback.apply(null, args); }, wait);
                };
                debounced.cancel = function () { clearTimeout(timer); };
                return debounced;
            }

            function element(tag, className, text) {
                var node = document.createElement(tag);
                if (className) { node.className = className; }
                if (text !== undefined) { node.textContent = text; }
                return node;
            }

            function Combobox(settings) {
                var input = settings.input;
                var list = settings.list;
                var selected = null;
                var items = [];
                var lastFound = [];
                var activeIndex = -1;
                var requestId = 0;
                var identifier = 'ua-' + Math.random().toString(36).slice(2, 9);
                var message = element('div', 'ua-list ua-status');
                var announcer = element('div', 'ua-sr-only');
                var fieldLabel = input.labels && input.labels[0];

                list.id = identifier;
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-controls', identifier);
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('autocomplete', 'off');
                list.setAttribute('role', 'listbox');
                if (fieldLabel) {
                    fieldLabel.id = fieldLabel.id || identifier + '-label';
                    list.setAttribute('aria-labelledby', fieldLabel.id);
                }
                message.hidden = true;
                message.setAttribute('aria-hidden', 'true');
                announcer.setAttribute('role', 'status');
                announcer.setAttribute('aria-live', 'polite');
                list.parentNode.appendChild(message);
                list.parentNode.appendChild(announcer);

                function label(item) {
                    return settings.locale === 'en' ? (item.name_en || item.name) : (item.name_ar || item.name);
                }

                function otherLabel(item) {
                    var other = settings.locale === 'en' ? item.name_ar : item.name_en;
                    return other && other !== label(item) ? other : '';
                }

                function close() {
                    list.hidden = true;
                    message.hidden = true;
                    announcer.textContent = '';
                    input.setAttribute('aria-expanded', 'false');
                    input.removeAttribute('aria-activedescendant');
                    activeIndex = -1;
                }

                function highlight(index) {
                    var options = list.querySelectorAll('.ua-option');
                    if (!options.length) { return; }
                    activeIndex = (index + options.length) % options.length;
                    options.forEach(function (option, position) {
                        option.setAttribute('aria-selected', position === activeIndex ? 'true' : 'false');
                    });
                    input.setAttribute('aria-activedescendant', options[activeIndex].id);
                    options[activeIndex].scrollIntoView({block: 'nearest'});
                }

                function showMessage(text) {
                    list.innerHTML = '';
                    list.hidden = true;
                    items = [];
                    activeIndex = -1;
                    message.textContent = text;
                    message.hidden = false;
                    announcer.textContent = text;
                    input.setAttribute('aria-expanded', 'false');
                    input.removeAttribute('aria-activedescendant');
                }

                function render(found) {
                    items = found;
                    lastFound = found;
                    if (!found.length) {
                        showMessage(settings.text.empty);
                        return;
                    }
                    list.innerHTML = '';
                    found.forEach(function (item, position) {
                        var option = element('li', 'ua-option');
                        option.id = identifier + '-' + position;
                        option.setAttribute('role', 'option');
                        option.setAttribute('aria-selected', 'false');
                        option.appendChild(element('span', '', label(item)));
                        var secondary = otherLabel(item);
                        if (secondary) {
                            var other = element('small', '', secondary);
                            other.setAttribute('lang', settings.locale === 'en' ? 'ar' : 'en');
                            other.setAttribute('dir', settings.locale === 'en' ? 'rtl' : 'ltr');
                            option.appendChild(other);
                        }
                        option.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            choose(item);
                        });
                        list.appendChild(option);
                    });
                    message.hidden = true;
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                    announcer.textContent = settings.text.results + ' ' + found.length;
                    highlight(0);
                }

                function choose(item) {
                    selected = item;
                    input.value = label(item);
                    close();
                    settings.onChoose(item);
                }

                function search() {
                    var current = ++requestId;
                    if (settings.beforeSearch && settings.beforeSearch() === false) {
                        showMessage(settings.text.blocked);
                        return;
                    }
                    var query = selected && input.value === label(selected) ? '' : input.value;
                    showMessage(settings.text.loading);
                    Promise.resolve(settings.fetchItems(query)).then(function (found) {
                        if (current === requestId) { render(found); }
                    }).catch(function () {
                        if (current === requestId) { showMessage(settings.text.failed); }
                    });
                }

                var scheduleSearch = debounce(search, DEBOUNCE_MS);

                input.addEventListener('focus', function () { if (!input.disabled && !input.hasAttribute('data-ua-quiet')) { search(); } });
                input.addEventListener('click', function () { if (!input.disabled && list.hidden && message.hidden) { search(); } });
                input.addEventListener('input', function () {
                    if (selected && input.value !== label(selected)) {
                        selected = null;
                        settings.onClear();
                    }
                    items = [];
                    activeIndex = -1;
                    scheduleSearch();
                });
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowDown') { event.preventDefault(); list.hidden ? search() : highlight(activeIndex + 1); }
                    else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(activeIndex - 1); }
                    else if (event.key === 'Enter' && !list.hidden && items[activeIndex]) { event.preventDefault(); choose(items[activeIndex]); }
                    else if (event.key === 'Escape') { close(); }
                });
                input.addEventListener('blur', function () {
                    scheduleSearch.cancel();
                    requestId++;
                    close();
                    if (selected || input.value === '') { return; }
                    var exact = lastFound.filter(function (item) { return sameName(item, input.value); });
                    if (exact.length === 1) { choose(exact[0]); } else { input.value = ''; }
                });

                return {
                    select: function (item) { selected = item; input.value = item ? label(item) : ''; },
                    clear: function () { selected = null; lastFound = []; input.value = ''; scheduleSearch.cancel(); requestId++; close(); },
                    selected: function () { return selected; },
                    close: close,
                };
            }

            function UnifiedAddress(root) {
                var locale = root.getAttribute('data-locale') || document.documentElement.lang || 'ar';
                var text = {
                    empty: root.getAttribute('data-text-empty') || '',
                    regionEmpty: root.getAttribute('data-text-region-empty') || '',
                    results: root.getAttribute('data-text-results') || '',
                    loading: root.getAttribute('data-text-loading') || '',
                    failed: root.getAttribute('data-text-failed') || '',
                    blocked: root.getAttribute('data-text-region-first') || '',
                    selectFromList: root.getAttribute('data-text-select') || '',
                    nationalFormat: root.getAttribute('data-text-national') || '',
                };
                var citiesUrl = root.getAttribute('data-cities-url');
                var saudiValues = JSON.parse(root.getAttribute('data-saudi-values') || '[]').map(function (value) { return String(value).toLowerCase(); });
                var regionSelect = root.querySelector('[data-ua-region]');
                var regionInput = root.querySelector('[data-ua-region-input]');
                var regionList = root.querySelector('[data-ua-region-list]');
                var cityInput = root.querySelector('[data-ua-input]');
                var cityList = root.querySelector('[data-ua-list]');
                var cityId = root.querySelector('[data-ua-city-id]');
                var national = root.querySelector('[data-ua-national]');
                var scope = root.closest('form') || root;
                var countrySelector = root.getAttribute('data-ua-country-selector');
                var countryControl = countrySelector ? scope.querySelector(countrySelector) : null;
                var regionRequired = regionSelect.required;
                var cityPlaceholder = cityInput.getAttribute('placeholder') || '';
                var currentRegion = regionSelect.value;
                var cache = {};
                var controller = null;

                function regionItems() {
                    return Array.prototype.slice.call(regionSelect.options).filter(function (option) { return option.value !== ''; }).map(function (option) {
                        return {id: option.value, name: option.text, name_ar: option.getAttribute('data-name-ar') || option.text, name_en: option.getAttribute('data-name-en') || option.text};
                    });
                }

                function announce() {
                    root.dispatchEvent(new CustomEvent('unified-address:change', {bubbles: true, detail: {regionId: regionSelect.value, cityId: cityId.value}}));
                }

                function updateCityValidity() {
                    var missing = !cityId.value && !cityInput.disabled && cityInput.hasAttribute('data-ua-required');
                    cityInput.setCustomValidity(missing ? text.selectFromList : '');
                }

                function setRegionDependentState() {
                    var regionChosen = regionSelect.value !== '';
                    cityInput.disabled = !isSaudi() || !regionChosen;
                    cityInput.setAttribute('placeholder', regionChosen || text.blocked === '' ? cityPlaceholder : text.blocked);
                    updateCityValidity();
                }

                function fetchCities(query) {
                    var key = regionSelect.value + '|' + query;
                    if (cache[key]) { return Promise.resolve(cache[key]); }
                    if (controller) { controller.abort(); }
                    var request = typeof AbortController === 'function' ? new AbortController() : null;
                    controller = request;
                    var timeout = request ? setTimeout(function () { request.abort(); }, FETCH_TIMEOUT_MS) : null;
                    var url = citiesUrl + '?region_id=' + encodeURIComponent(regionSelect.value) + '&limit=' + PAGE_SIZE + (query ? '&q=' + encodeURIComponent(query) : '');
                    return fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: request ? request.signal : undefined})
                        .then(function (response) { if (!response.ok) { throw new Error('status ' + response.status); } return response.json(); })
                        .then(function (body) { cache[key] = body.cities || []; return cache[key]; })
                        .finally(function () { clearTimeout(timeout); });
                }

                var cityCombobox = Combobox({
                    input: cityInput, list: cityList, locale: locale, text: text,
                    beforeSearch: function () { return regionSelect.value !== ''; },
                    fetchItems: fetchCities,
                    onChoose: function (item) { cityId.value = item.id; updateCityValidity(); announce(); },
                    onClear: function () { cityId.value = ''; updateCityValidity(); announce(); },
                });

                var regionCombobox = regionInput ? Combobox({
                    input: regionInput, list: regionList, locale: locale, text: Object.assign({}, text, {empty: text.regionEmpty}),
                    fetchItems: function (query) {
                        var typed = fold(query);
                        return regionItems().filter(function (item) { return typed === '' || matchesTyped(item.name_ar, query) || matchesTyped(item.name_en, query); });
                    },
                    onChoose: function (item) { changeRegion(item.id); },
                    onClear: function () { changeRegion(''); },
                }) : null;

                function changeRegion(value) {
                    if (value === currentRegion) { return; }
                    currentRegion = value;
                    regionSelect.value = value;
                    cityCombobox.clear();
                    cityId.value = '';
                    cache = {};
                    setRegionDependentState();
                    announce();
                }

                function isSaudi() {
                    return !countryControl || saudiValues.indexOf(String(countryControl.value).trim().toLowerCase()) !== -1;
                }

                function applyCountry() {
                    var saudi = isSaudi();
                    scope.querySelectorAll('[data-ua-saudi]').forEach(function (block) { toggle(block, saudi); });
                    scope.querySelectorAll('[data-ua-other]').forEach(function (block) { toggle(block, !saudi); });
                    if (regionCombobox) {
                        regionInput.disabled = !saudi;
                        regionInput.required = saudi && regionRequired;
                    }
                    setRegionDependentState();
                }

                function toggle(block, visible) {
                    block.hidden = !visible;
                    block.querySelectorAll('input, select, textarea').forEach(function (control) {
                        if (control.hasAttribute('data-ua-always')) { return; }
                        control.disabled = !visible || (control === cityInput && regionSelect.value === '');
                    });
                }

                if (regionCombobox) {
                    regionSelect.hidden = true;
                    regionInput.hidden = false;
                    regionSelect.required = false;
                }
                if (regionCombobox && regionSelect.value !== '') {
                    var current = regionItems().filter(function (item) { return item.id === regionSelect.value; })[0];
                    regionCombobox.select(current || null);
                }
                if (cityId.value !== '' && cityInput.value !== '') {
                    cityCombobox.select({id: cityId.value, name: cityInput.value, name_ar: cityInput.getAttribute('data-name-ar') || cityInput.value, name_en: cityInput.getAttribute('data-name-en') || cityInput.value});
                    cityInput.value = locale === 'en' ? (cityInput.getAttribute('data-name-en') || cityInput.value) : (cityInput.getAttribute('data-name-ar') || cityInput.value);
                }
                if (!regionCombobox) {
                    regionSelect.addEventListener('change', function () { changeRegion(regionSelect.value); });
                }
                if (national) {
                    var nationalShape = new RegExp('^(?:' + national.getAttribute('pattern') + ')$');
                    national.addEventListener('input', function () {
                        var cleaned = normalizeNationalAddress(national.value);
                        if (cleaned !== national.value) { national.value = cleaned; }
                        national.setCustomValidity('');
                    });
                    national.addEventListener('blur', function () {
                        var valid = national.value === '' || nationalShape.test(national.value);
                        national.setCustomValidity(valid ? '' : text.nationalFormat);
                    });
                }
                if (countryControl) {
                    countryControl.addEventListener('change', applyCountry);
                    if (window.jQuery) { window.jQuery(countryControl).on('change', applyCountry); }
                }

                setRegionDependentState();
                applyCountry();
            }

            function visibleControl(control) {
                var root = control.closest('[data-unified-address]');
                if (root && control.hasAttribute('data-ua-city-id')) { return root.querySelector('[data-ua-input]'); }
                if (root && control.hasAttribute('data-ua-region') && control.hidden) { return root.querySelector('[data-ua-region-input]'); }
                return control;
            }

            function renderedControl(name) {
                var matches = document.querySelectorAll('[name="' + name + '"]');
                for (var position = 0; position < matches.length; position++) {
                    var visible = visibleControl(matches[position]);
                    if (visible && visible.getClientRects().length > 0) { return visible; }
                }
                return null;
            }

            function markInvalid(control, text) {
                var feedback = element('div', 'invalid-feedback d-block', text);
                feedback.id = (control.id || 'ua-field') + '-error';
                var anchor = control.closest('[data-ua-combobox]') || control;
                anchor.insertAdjacentElement('afterend', feedback);
                control.classList.add('is-invalid');
                control.setAttribute('aria-invalid', 'true');
                control.setAttribute('aria-describedby', ((control.getAttribute('aria-describedby') || '') + ' ' + feedback.id).trim());

                function release() {
                    feedback.remove();
                    control.classList.remove('is-invalid');
                    control.removeAttribute('aria-invalid');
                    var described = (control.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return id !== feedback.id; }).join(' ');
                    if (described) { control.setAttribute('aria-describedby', described); } else { control.removeAttribute('aria-describedby'); }
                }

                ['input', 'change'].forEach(function (name) { control.addEventListener(name, release, {once: true}); });
                var root = control.closest('[data-unified-address]');
                if (root) {
                    root.addEventListener('unified-address:change', function settle(event) {
                        var stillMissing = (control.hasAttribute('data-ua-input') && !event.detail.cityId) || (control.hasAttribute('data-ua-region-input') && !event.detail.regionId);
                        if (stillMissing || !control.matches('[data-ua-input], [data-ua-region-input]')) { return; }
                        root.removeEventListener('unified-address:change', settle);
                        release();
                    });
                }
            }

            /** Moves the focus to a box that was refused without opening its list over the message under it. */
            function focusQuietly(control) {
                control.setAttribute('data-ua-quiet', '');
                control.focus();
                control.removeAttribute('data-ua-quiet');
            }

            function markRefusedFields(refusal) {
                var prefix = refusal.prefix || '';
                var focused = false;
                Object.keys(refusal.fields).forEach(function (field) {
                    var control = renderedControl(prefix + field);
                    if (!control) { return; }
                    markInvalid(control, [].concat(refusal.fields[field])[0]);
                    if (!focused) { focusQuietly(control); focused = true; }
                });
            }

            function init(scope) {
                (scope || document).querySelectorAll('[data-unified-address]:not([data-ua-ready])').forEach(function (root) {
                    root.setAttribute('data-ua-ready', '1');
                    UnifiedAddress(root);
                });
            }

            function initOpened(event) { init(event.target); }

            function ready() {
                if (window.jQuery) {
                    window.jQuery(document).on('shown.bs.modal shown.bs.offcanvas', initOpened);
                    window.jQuery(document).on('ajaxError', function (event, request) {
                        var refusal = request.responseJSON;
                        if (refusal && refusal.fields && typeof refusal.fields === 'object') { markRefusedFields(refusal); }
                    });
                }
                init(document);
            }

            window.unifiedAddress = {init: init, fold: fold};

            ['shown.bs.modal', 'shown.bs.offcanvas'].forEach(function (name) { document.addEventListener(name, initOpened); });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', ready);
            } else {
                ready();
            }
        })();
    </script>
@endonce
