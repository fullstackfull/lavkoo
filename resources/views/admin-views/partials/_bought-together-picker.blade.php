{{-- "Frequently bought together" picker for the product form.

     Products are searched by name against the admin picker endpoint and kept as an ordered list of
     ids in one hidden input, so the form posts a single value and the storefront shows the
     companions in exactly the order they were picked. Leaving it empty is a valid answer: the
     storefront then falls back to what customers actually buy together. --}}
@php($selectedBoughtTogether = old('bought_together_ids', $boughtTogetherIds ?? ''))
<div class="form-group bt-picker"
     data-url-options="{{ route('admin.products.picker-options') }}"
     data-url-labels="{{ route('admin.products.picker-labels') }}">
    <label class="form-label" for="bought-together-search">
        {{ translate('frequently_bought_together') }}
        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
              data-bs-title="{{ translate('these_products_are_suggested_beside_this_one_on_its_page_leave_empty_to_use_what_customers_buy_together') }}">
            <i class="fi fi-sr-info"></i>
        </span>
    </label>

    <input type="hidden" name="bought_together_ids" value="{{ $selectedBoughtTogether }}" class="bt-picker__value">
    <div class="bt-picker__chips mb-2"></div>
    <input type="search" class="form-control bt-picker__search" id="bought-together-search"
           placeholder="{{ translate('search_to_pick') }}" autocomplete="off">
    <div class="bt-picker__results" hidden></div>
</div>

@once
    @push('script')
        <script>
            'use strict';
            document.querySelectorAll('.bt-picker').forEach(function (picker) {
                var value = picker.querySelector('.bt-picker__value');
                var chips = picker.querySelector('.bt-picker__chips');
                var search = picker.querySelector('.bt-picker__search');
                var results = picker.querySelector('.bt-picker__results');
                var timer = null;

                function ids() { return value.value.split(',').filter(Boolean); }
                function write(list) { value.value = list.join(','); }

                function paint(labels) {
                    chips.innerHTML = '';
                    ids().forEach(function (id) {
                        var chip = document.createElement('span');
                        chip.className = 'bt-picker__chip';
                        chip.textContent = labels[id] || ('#' + id);

                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.innerHTML = '&times;';
                        remove.addEventListener('click', function () {
                            write(ids().filter(function (other) { return other !== id; }));
                            refresh();
                        });

                        chip.appendChild(remove);
                        chips.appendChild(chip);
                    });
                }

                function refresh() {
                    if (!ids().length) return paint({});
                    fetch(picker.dataset.urlLabels + '?ids=' + encodeURIComponent(ids().join(',')), {
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(function (response) { return response.json(); })
                      .then(function (body) {
                          var labels = {};
                          (body.options || []).forEach(function (option) { labels[String(option.value)] = option.label; });
                          paint(labels);
                      }).catch(function () { paint({}); });
                }

                function run() {
                    fetch(picker.dataset.urlOptions + '?searchValue=' + encodeURIComponent(search.value), {
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(function (response) { return response.json(); })
                      .then(function (body) {
                          results.innerHTML = '';
                          (body.options || []).forEach(function (option) {
                              var row = document.createElement('button');
                              row.type = 'button';
                              row.textContent = option.label;
                              row.addEventListener('click', function () {
                                  var list = ids();
                                  if (list.indexOf(String(option.value)) === -1) list.push(String(option.value));
                                  write(list);
                                  search.value = '';
                                  results.hidden = true;
                                  refresh();
                              });
                              results.appendChild(row);
                          });
                          results.hidden = false;
                      }).catch(function () { results.hidden = true; });
                }

                search.addEventListener('focus', run);
                search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 220); });
                search.addEventListener('blur', function () { setTimeout(function () { results.hidden = true; }, 180); });
                search.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); });

                refresh();
            });
        </script>
    @endpush

    @push('css_or_js')
        <style>
            .bt-picker { position: relative; }
            .bt-picker__chips { display: flex; flex-wrap: wrap; gap: .3rem; }
            .bt-picker__chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem;
                padding: .25rem .4rem .25rem .6rem; border-radius: 100px; background: var(--bs-primary-bg-subtle, #eef1ff); }
            .bt-picker__chip button { border: 0; background: transparent; cursor: pointer; line-height: 1; opacity: .6; }
            .bt-picker__chip button:hover { opacity: 1; }
            .bt-picker__results { position: absolute; z-index: 20; inset-inline: 0; max-height: 220px; overflow-y: auto;
                display: flex; flex-direction: column; padding: .25rem; border-radius: .5rem; background: #fff;
                border: 1px solid var(--bs-border-color, #e6e9ef); box-shadow: 0 12px 30px rgba(0,0,0,.12); }
            .bt-picker__results button { text-align: start; font-size: .8rem; padding: .4rem .5rem; border: 0;
                border-radius: .35rem; background: transparent; cursor: pointer; }
            .bt-picker__results button:hover { background: var(--bs-secondary-bg, #f3f5f9); }
        </style>
    @endpush
@endonce
