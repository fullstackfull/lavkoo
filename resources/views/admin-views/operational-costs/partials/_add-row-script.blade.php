{{-- "Add a row" for a table of repeated inputs: a button with data-add-row="name" copies the last row of the table with data-repeat="name" and numbers its fields. --}}
@push('script')
    <script>
        (function () {
            document.querySelectorAll('[data-add-row]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var body = document.querySelector('[data-repeat="' + button.getAttribute('data-add-row') + '"] tbody');
                    var next = body.children.length;
                    var row = body.lastElementChild.cloneNode(true);
                    row.querySelectorAll('input, select').forEach(function (field) {
                        field.name = field.name.replace(/\[\d+\]/, '[' + next + ']');
                        if (field.tagName === 'INPUT') { field.value = ''; } else { field.selectedIndex = 0; }
                    });
                    body.appendChild(row);
                });
            });
        })();
    </script>
@endpush
