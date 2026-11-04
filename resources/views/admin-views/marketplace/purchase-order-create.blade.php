@extends('layouts.admin.app')

@section('title', translate('new_purchase_order'))

@section('content')
    <div class="content container-fluid">
        <h2 class="h1 mb-3">{{ translate('new_purchase_order') }}</h2>

        <form action="{{ route('admin.marketplace.purchase-orders.store') }}" method="post">
            @csrf
            <div class="card mb-3">
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label fs-12">{{ translate('supplier') }} *</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">{{ translate('select_supplier') }}</option>
                            @foreach ($suppliers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        @if ($suppliers->isEmpty())
                            <small class="text-danger">{{ translate('add_a_supplier_first') }} — <a href="{{ route('admin.marketplace.suppliers.index') }}">{{ translate('suppliers') }}</a></small>
                        @endif
                    </div>
                    <div class="col-md-2"><label class="form-label fs-12">{{ translate('currency') }}</label>
                        <input type="text" name="currency" class="form-control" maxlength="10" placeholder="USD"></div>
                    <div class="col-md-3"><label class="form-label fs-12">{{ translate('expected_date') }}</label>
                        <input type="date" name="expected_at" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label fs-12">{{ translate('notes') }}</label>
                        <input type="text" name="notes" class="form-control" maxlength="1000"></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ translate('lines') }}</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-line">+ {{ translate('add_line') }}</button>
                </div>
                <div class="card-body p-0">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead><tr>
                                <th style="width:130px">{{ translate('product_id') }}</th>
                                <th>{{ translate('description') }}</th>
                                <th style="width:120px">{{ translate('sku') }}</th>
                                <th style="width:100px">{{ translate('qty') }} *</th>
                                <th style="width:130px">{{ translate('unit_cost') }}</th>
                                <th style="width:40px"></th>
                            </tr></thead>
                            <tbody id="lines">
                                <tr>
                                    <td><input type="number" name="items[0][product_id]" class="form-control form-control-sm" min="1"></td>
                                    <td><input type="text" name="items[0][description]" class="form-control form-control-sm"></td>
                                    <td><input type="text" name="items[0][sku]" class="form-control form-control-sm"></td>
                                    <td><input type="number" name="items[0][qty_ordered]" class="form-control form-control-sm" min="1" value="1" required></td>
                                    <td><input type="number" step="0.01" name="items[0][unit_cost]" class="form-control form-control-sm" min="0" value="0"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger remove-line">&times;</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('admin.marketplace.purchase-orders.index') }}" class="btn btn-outline-secondary">{{ translate('cancel') }}</a>
                <button class="btn btn-primary">{{ translate('create_draft') }}</button>
            </div>
        </form>
    </div>

    <script>
        (function () {
            let i = 1;
            const body = document.getElementById('lines');
            document.getElementById('add-line').addEventListener('click', function () {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="number" name="items[${i}][product_id]" class="form-control form-control-sm" min="1"></td>
                    <td><input type="text" name="items[${i}][description]" class="form-control form-control-sm"></td>
                    <td><input type="text" name="items[${i}][sku]" class="form-control form-control-sm"></td>
                    <td><input type="number" name="items[${i}][qty_ordered]" class="form-control form-control-sm" min="1" value="1" required></td>
                    <td><input type="number" step="0.01" name="items[${i}][unit_cost]" class="form-control form-control-sm" min="0" value="0"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger remove-line">&times;</button></td>`;
                body.appendChild(tr);
                i++;
            });
            body.addEventListener('click', function (e) {
                if (e.target.classList.contains('remove-line') && body.rows.length > 1) {
                    e.target.closest('tr').remove();
                }
            });
        })();
    </script>
@endsection
