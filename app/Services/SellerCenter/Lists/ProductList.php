<?php

namespace App\Services\SellerCenter\Lists;

use App\Models\Product;
use App\Models\SellerInsight;
use App\Services\SellerCenter\Status;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue behind `/seller/products`.
 *
 * The screen's whole point is the listing *problem*, not just the listing (handoff 07.7): every row
 * carries the precise reason it is not selling, taken from the issue store rather than guessed from
 * the product row. A product with no issue leaves that cell empty — the word "Error" on its own is
 * a defect, because it tells the seller nothing they can act on.
 */
class ProductList
{
    /** Saved views over the same query, not separate screens. */
    public const VIEWS = [
        'all' => ['label' => 'all', 'tone' => 'neutral'],
        'active' => ['label' => 'active', 'tone' => 'neutral'],
        'draft' => ['label' => 'drafts', 'tone' => 'neutral'],
        'under_review' => ['label' => 'under_review', 'tone' => 'medium'],
        'rejected' => ['label' => 'rejected', 'tone' => 'critical'],
        'issues' => ['label' => 'product_issues', 'tone' => 'high'],
        'out_of_stock' => ['label' => 'out_of_stock', 'tone' => 'critical'],
    ];

    public function paginate(int $sellerId, Request $request): LengthAwarePaginator
    {
        $view = $this->view($request);

        $query = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId]);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('name', 'like', '%' . $search . '%')
                    ->orWhere('code', 'like', $search . '%');
            });
        }

        match ($view) {
            'active' => $query->where(['status' => 1, 'request_status' => 1]),
            'draft' => $query->where('request_status', 0),
            'under_review' => $query->where('request_status', 0)->where('status', 1),
            'rejected' => $query->where('request_status', 2),
            'out_of_stock' => $query->where('current_stock', '<=', 0),
            'issues' => $query->whereIn('id', $this->productIdsWithIssues($sellerId)),
            default => null,
        };

        $this->sort($query, $request);

        return $query->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * The open issue for each of these products, keyed by product id.
     *
     * Read from the issue store rather than recomputed here: the thresholds that decide "low cover"
     * or "missing attribute" live in the detectors, and a second definition would eventually
     * disagree with the Control Tower's count.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, SellerInsight>
     */
    public function issuesFor(int $sellerId, array $productIds): array
    {
        if ($productIds === [] || !Schema::hasTable('seller_insights')) {
            return [];
        }

        return SellerInsight::forSeller($sellerId)
            ->open()
            ->where('entity_type', 'product')
            ->whereIn('entity_id', $productIds)
            ->orderBySeverity()
            ->get()
            ->keyBy(fn (SellerInsight $issue) => (int) $issue->entity_id)
            ->all();
    }

    /**
     * The status word a product row shows.
     *
     * `request_status` is the marketplace's decision and `status` is the seller's, so the two have
     * to be read together — a rejected product that the seller left switched on is rejected, not
     * active.
     */
    public function statusOf(Product $product): string
    {
        return match (true) {
            (int) $product->request_status === 2 => 'rejected',
            (int) $product->request_status === 0 => 'under_review',
            (int) $product->status === 0 => 'draft',
            (int) $product->current_stock <= 0 => 'out_of_stock',
            default => 'active',
        };
    }

    /**
     * Listing quality as a percentage of the fields a complete listing carries.
     *
     * Counted from the row rather than stored, and deliberately simple: every part of the score is
     * something the seller can see and fix on the product page.
     */
    public function listingQuality(Product $product): int
    {
        $checks = [
            trim((string) $product->getRawOriginal('name')) !== '',
            trim((string) $product->getRawOriginal('details')) !== '',
            !empty($product->thumbnail),
            count(json_decode((string) $product->images, true) ?: []) > 1,
            !empty($product->code),
            (float) $product->unit_price > 0,
            !empty($product->category_ids) && $product->category_ids !== '[]',
            !empty($product->brand_id),
        ];

        return (int) round((count(array_filter($checks)) / count($checks)) * 100);
    }

    public function qualityTone(int $percent): string
    {
        return match (true) {
            $percent >= 80 => Status::GOOD,
            $percent >= 60 => Status::MEDIUM,
            default => Status::HIGH,
        };
    }

    public function filterFields(): array
    {
        return [
            'status' => ['label' => 'status', 'type' => 'enum', 'group' => 'catalog', 'options' => [
                ['value' => 'active', 'label' => translate('active')],
                ['value' => 'draft', 'label' => translate('draft')],
                ['value' => 'under_review', 'label' => translate('under_review')],
                ['value' => 'rejected', 'label' => translate('rejected')],
                ['value' => 'out_of_stock', 'label' => translate('out_of_stock')],
            ]],
            'issues' => ['label' => 'issues', 'type' => 'enum', 'group' => 'catalog', 'tone' => 'high', 'options' => [
                ['value' => 'any', 'label' => translate('any')],
            ]],
        ];
    }

    /** The view a request is asking for, accepting the Control Tower's `?issues=any` drill-down. */
    public function view(Request $request): string
    {
        if ($request->query('issues') === 'any') {
            return 'issues';
        }

        $view = (string) ($request->query('view') ?? $request->query('status') ?? 'all');

        return array_key_exists($view, self::VIEWS) ? $view : 'all';
    }

    /** @return array<int, int> */
    private function productIdsWithIssues(int $sellerId): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return [];
        }

        return SellerInsight::forSeller($sellerId)
            ->open()
            ->where('entity_type', 'product')
            ->pluck('entity_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function sort($query, Request $request): void
    {
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        match ((string) $request->query('sort', '')) {
            'product' => $query->orderBy('name', $direction),
            'price' => $query->orderBy('unit_price', $direction),
            'stock' => $query->orderBy('current_stock', $direction),
            // A catalogue opens on what changed most recently, which is what a seller is looking for.
            default => $query->orderByDesc('updated_at'),
        };
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
