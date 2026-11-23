<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Models\Product;
use App\Services\Catalog\ProductCodeScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Finds our products for the screens that link them to a channel's listings, the way the Torod Point matching page does: admin-owned physical products only. */
final class ProductDirectory
{
    private const SEARCH_LIMIT = 20;

    public function __construct(private readonly ProductCodeScope $codeScope)
    {
    }

    /**
     * The products behind some ids, for showing their names.
     *
     * @param iterable<int|string|null> $ids
     * @return Collection<int, Product> keyed by product id
     */
    public function named(iterable $ids): Collection
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Product::withoutGlobalScope('translate')->whereIn('id', $ids)->get(['id', 'name', 'code', 'barcode'])->keyBy('id');
    }

    /** @return Collection<int, object{id: int, name: string, code: string|null, barcode: string|null}|array{id: int, name: string, code: string|null, barcode: string|null, country_tag: string}> */
    public function search(string $term): Collection
    {
        $rows = DB::table('products')
            ->where('added_by', 'admin')->where('product_type', 'physical')
            ->where(static fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get($this->codeScope->pickerColumns(columns: ['id', 'name', 'code', 'barcode']));

        return $this->codeScope->tagPickerRows(rows: $rows);
    }
}
