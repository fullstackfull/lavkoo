@once
    <style>
        .unified-address [data-ua-combobox] { position: relative; }
        .unified-address .ua-list { position: absolute; inset-inline: 0; top: 100%; z-index: 1060; max-height: 16rem; overflow-y: auto; margin: .125rem 0 0; padding: 0; list-style: none; background: #fff; color: #212529; border: 1px solid #ced4da; border-radius: .25rem; box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15); }
        .unified-address .ua-list[hidden] { display: none; }
        .unified-address .ua-option { display: flex; justify-content: space-between; align-items: center; gap: .75rem; min-height: 44px; padding: .5rem .75rem; cursor: pointer; text-align: start; }
        .unified-address .ua-option[aria-selected="true"], .unified-address .ua-option:hover { background: #e9f2ff; }
        .unified-address .ua-option small, .unified-address .ua-status { color: #6c757d; }
        .unified-address .ua-status { padding: .5rem .75rem; text-align: start; }
        .unified-address input[data-ua-input], .unified-address select[data-ua-region], .unified-address input[data-ua-national] { min-height: 44px; font-size: 16px; }
        .unified-address input[data-ua-national] { direction: ltr; text-transform: uppercase; letter-spacing: .08em; }
        [dir="rtl"] .unified-address input[data-ua-national] { text-align: right; }
    </style>
    <script>
        (function () {
            'use strict';

            var DEBOUNCE_MS = 180;
            var PAGE_SIZE = 50;
            var LETTERS = {'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ى': 'ي', 'ئ': 'ي', 'ؤ': 'و', 'ة': 'ه'};

            function fold(text) {
                return String(text || '')
                    .toLowerCase()
                    .replace(/[٠-٩]/g, function (digit) { return String(digit.charCodeAt(0) - 1632); })
                    .replace(/[ً-ٰٟـ]/g, '')
                    .replace(/[أإآٱىئؤة]/g, function (letter) { return LETTERS[letter]; })
                    .replace(/[^\p{L}\p{N}]+/gu, '');
            }

            function normalizeNationalAddress(text) {
                return String(text || '')
                    .replace(/[٠-٩]/g, function (digit) { return String(digit.charCodeAt(0) - 1632); })
                    .replace(/[۰-۹]/g, function (digit) { return String(digit.charCodeAt(0) - 1776); })
                    .replace(/[\s\-_.​-‏‪-‮⁦-⁩﻿]+/g, '')
                    .toUpperCase();
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
                var activeIndex = -1;
                var requestId = 0;
                var identifier = 'ua-' + Math.random().toString(36).slice(2, 9);

                list.id = identifier;
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-controls', identifier);
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('autocomplete', 'off');
                list.setAttribute('role', 'listbox');

                function label(item) {
                    return settings.locale === 'en' ? (item.name_en || item.name) : (item.name_ar || item.name);
                }

                function otherLabel(item) {
                    var other = settings.locale === 'en' ? item.name_ar : item.name_en;
                    return other && other !== label(item) ? other : '';
                }

                function close() {
                    list.hidden = true;
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

                function showMessage(message) {
                    list.innerHTML = '';
                    items = [];
                    list.appendChild(element('li', 'ua-status', message));
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }

                function render(found) {
                    items = found;
                    list.innerHTML = '';
                    if (!found.length) {
                        showMessage(settings.text.empty);
                        return;
                    }
                    found.forEach(function (item, position) {
                        var option = element('li', 'ua-option');
                        option.id = identifier + '-' + position;
                        option.setAttribute('role', 'option');
                        option.setAttribute('aria-selected', 'false');
                        option.appendChild(element('span', '', label(item)));
                        var secondary = otherLabel(item);
                        if (secondary) { option.appendChild(element('small', '', secondary)); }
                        option.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            choose(item);
                        });
                        list.appendChild(option);
                    });
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
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

                input.addEventListener('focus', function () { if (!input.disabled) { search(); } });
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
                    if (!selected && input.value !== '') { input.value = ''; }
                });

                return {
                    select: function (item) { selected = item; input.value = item ? label(item) : ''; },
                    clear: function () { selected = null; input.value = ''; scheduleSearch.cancel(); requestId++; close(); },
                    selected: function () { return selected; },
                    close: close,
                };
            }

            function UnifiedAddress(root) {
                var locale = root.getAttribute('data-locale') || document.documentElement.lang || 'ar';
                var text = {
                    empty: root.getAttribute('data-text-empty') || '',
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
                    cityInput.disabled = !isSaudi() || regionSelect.value === '';
                    updateCityValidity();
                }

                function fetchCities(query) {
                    var key = regionSelect.value + '|' + query;
                    if (cache[key]) { return Promise.resolve(cache[key]); }
                    if (controller) { controller.abort(); }
                    controller = typeof AbortController === 'function' ? new AbortController() : null;
                    var url = citiesUrl + '?region_id=' + encodeURIComponent(regionSelect.value) + '&limit=' + PAGE_SIZE + (query ? '&q=' + encodeURIComponent(query) : '');
                    return fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: controller ? controller.signal : undefined})
                        .then(function (response) { if (!response.ok) { throw new Error('status ' + response.status); } return response.json(); })
                        .then(function (body) { cache[key] = body.cities || []; return cache[key]; });
                }

                var cityCombobox = Combobox({
                    input: cityInput, list: cityList, locale: locale, text: text,
                    beforeSearch: function () { return regionSelect.value !== ''; },
                    fetchItems: fetchCities,
                    onChoose: function (item) { cityId.value = item.id; updateCityValidity(); announce(); },
                    onClear: function () { cityId.value = ''; updateCityValidity(); announce(); },
                });

                var regionCombobox = regionInput ? Combobox({
                    input: regionInput, list: regionList, locale: locale, text: text,
                    fetchItems: function (query) {
                        var wanted = fold(query);
                        return regionItems().filter(function (item) { return wanted === '' || fold(item.name_ar + item.name_en).indexOf(wanted) !== -1; });
                    },
                    onChoose: function (item) { changeRegion(item.id); },
                    onClear: function () { changeRegion(''); },
                }) : null;

                function changeRegion(value) {
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

            function init(scope) {
                (scope || document).querySelectorAll('[data-unified-address]:not([data-ua-ready])').forEach(function (root) {
                    root.setAttribute('data-ua-ready', '1');
                    UnifiedAddress(root);
                });
            }

            function initOpened(event) { init(event.target); }

            function ready() {
                if (window.jQuery) { window.jQuery(document).on('shown.bs.modal shown.bs.offcanvas', initOpened); }
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
