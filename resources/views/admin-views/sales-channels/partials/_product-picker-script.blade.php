{{-- Fills the product pickers of the page from the search endpoint. Results are shown as text, never as markup. --}}
<script>
    (function () {
        document.querySelectorAll('[data-product-picker]').forEach(function (picker) {
            var search = picker.querySelector('[data-product-search]');
            var results = picker.querySelector('[data-product-results]');
            var target = picker.querySelector('[data-product-id]');
            var timer = null;
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetch(picker.dataset.url + '?q=' + encodeURIComponent(search.value), {headers: {'Accept': 'application/json'}})
                        .then(function (response) { return response.json(); })
                        .then(function (products) {
                            results.replaceChildren();
                            products.forEach(function (product) {
                                var choice = document.createElement('button');
                                choice.type = 'button';
                                choice.className = 'btn btn-outline-secondary btn-sm';
                                choice.textContent = '#' + product.id + ' ' + product.name + (product.code ? ' (' + product.code + ')' : '');
                                choice.addEventListener('click', function () {
                                    target.value = product.id;
                                    results.replaceChildren();
                                });
                                results.appendChild(choice);
                            });
                        });
                }, 250);
            });
        });
    })();
</script>
