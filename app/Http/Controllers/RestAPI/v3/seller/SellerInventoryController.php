<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\BatchService;
use App\Services\Marketplace\InventoryService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\WarehouseService;
use App\Services\SellerCenter\ModuleFlags;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The seller's own stock, with the history behind every number.
 *
 * The marketplace has kept a stock ledger since Phase 3 — every movement with its signed change, the
 * balance it left behind, a reason and who did it — and no seller has ever been able to see it. From
 * the app a stock level was a number that changed for no stated reason, which is exactly the kind of
 * figure people stop trusting and start keeping a spreadsheet beside.
 *
 * Adjusting goes through `InventoryService::adjust()` rather than writing the column: it locks the
 * row, refuses to drive the balance negative, and writes the movement that explains the change. An
 * adjustment without a reason is not offered, because a ledger of unexplained corrections is only
 * marginally better than no ledger.
 *
 * Warehouses and batches are answered only where the marketplace actually runs them. A seller with
 * no warehouses is told there are none rather than shown an empty module that implies they should
 * have some.
 */
class SellerInventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WarehouseService $warehouses,
        private readonly BatchService $batches,
    ) {
    }

    #[ApiDoc(
        summary: "The state of the seller's stock in one call",
        description: 'Counts of what is out of stock and what is running low, the total units on hand, '
            . 'and whether warehouses and batches are running for this seller at all — so a client can '
            . 'hide a module the marketplace does not use rather than showing an empty one. Every figure '
            . 'is counted from the catalogue; nothing is estimated.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function overview(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $products = $this->ownedProducts($sellerId);

        // The seller's own reorder level, or the marketplace's. Asked of the service every other
        // surface asks — the panel, the reports, the low-stock detector and the webhook that fires
        // when a product crosses it. This endpoint used to carry its own hardcoded five, so a
        // seller whose reorder level was ten was told one number on their phone and another at
        // their desk about the same shelf.
        $threshold = max(1, $this->inventory->stockLimitFor($sellerId));

        return response()->json([
            'low_stock_threshold' => $threshold,
            'products' => (clone $products)->count(),
            'out_of_stock' => (clone $products)->where('current_stock', '<=', 0)->count(),
            'running_low' => (clone $products)->where('current_stock', '>', 0)
                ->where('current_stock', '<=', $threshold)->count(),
            'units_on_hand' => (int) (clone $products)->sum('current_stock'),
            'movements_recorded' => Schema::hasTable('stock_movements')
                ? StockMovement::where('seller_id', $sellerId)->count()
                : 0,
            'reasons' => StockMovement::REASONS,
            'movement_types' => [
                StockMovement::TYPE_ADJUSTMENT, StockMovement::TYPE_RECEIPT,
                StockMovement::TYPE_SALE, StockMovement::TYPE_RETURN, StockMovement::TYPE_TRANSFER,
            ],
            // Architecturally present, operationally optional. A seller who has never been given a
            // warehouse should not be shown a warehouse screen.
            // The same answer the panel's navigation reads, from the same service.
            'warehouses_enabled' => ModuleFlags::hasWarehouses($sellerId),
            'batches_enabled' => ModuleFlags::hasBatches($sellerId),
        ], 200);
    }

    #[ApiDoc(
        summary: 'The stock movement log',
        description: 'Every recorded change to this seller\'s stock, newest first, with the signed '
            . 'change, the balance it left behind, its reason and who made it. Narrow it with '
            . 'product_id and type. Only this seller\'s movements are ever returned.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function movements(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $productId = $this->productIdFilter($request, $sellerId);

        if ($productId === false) {
            return $this->notFound('product', translate('product_not_found'));
        }

        $movements = $this->inventory->recent(
            productId: $productId,
            type: $this->typeFilter($request),
            perPage: $this->limit($request),
            // Without this the log would be every seller's.
            sellerId: $sellerId,
        );

        return response()->json([
            'total_size' => $movements->total(),
            'limit' => $movements->perPage(),
            'offset' => $movements->currentPage(),
            'movements' => collect($movements->items())->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'product_id' => $movement->product_id,
                'type' => $movement->type,
                'qty_change' => $movement->qty_change,
                'balance_after' => $movement->balance_after,
                'reason' => $movement->reason,
                'note' => $movement->note,
                'created_by_type' => $movement->created_by_type,
                'created_at' => $movement->created_at,
            ])->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Correct a stock level, with a reason',
        description: 'Applies a signed change through the stock ledger: the row is locked, the balance '
            . 'cannot be driven below zero, and a movement is written recording the change, the reason '
            . 'and who made it. A reason is required — a ledger of unexplained corrections is barely '
            . 'better than none. Answers 422 when the correction would take the balance negative, so '
            . 'the client can say why rather than showing a silent failure.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function adjust(Request $request, $id): JsonResponse
    {
        $sellerId = $request->seller->id;
        $product = $this->ownedProducts($sellerId)->find($id);

        if (!$product) {
            return $this->notFound('product', translate('product_not_found'));
        }

        $validator = Validator::make($request->all(), [
            // Signed: one field says both "add" and "remove", and zero is refused by the service.
            'delta' => 'required|integer|not_in:0',
            'reason' => 'required|string|in:' . implode(',', StockMovement::REASONS),
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $principal = $this->principal($request);

        $result = $this->inventory->adjust(
            productId: $product->id,
            delta: (int) $request['delta'],
            reason: $request['reason'],
            note: $request['note'],
            by: $principal->actorId(),
            byType: $principal->actorType(),
        );

        if (!($result['ok'] ?? false)) {
            return response()->json(['errors' => [
                ['code' => 'stock', 'message' => translate($result['reason'] ?? 'stock_adjustment_refused')],
            ]], 422);
        }

        return response()->json([
            'message' => translate('stock_updated'),
            'balance_after' => $result['balance_after'],
        ], 200);
    }

    #[ApiDoc(
        summary: 'Where this seller keeps their stock',
        description: 'The seller\'s warehouses and, for a given product, how many units sit in each '
            . 'plus how many are not placed anywhere. Answers an empty list when the marketplace does '
            . 'not run warehouses for this seller — that is the honest answer, not an error.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function warehouses(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $productId = $this->productIdFilter($request, $sellerId);

        if ($productId === false) {
            return $this->notFound('product', translate('product_not_found'));
        }

        return response()->json([
            'warehouses' => $this->warehousesFor($sellerId),
            // Only meaningful with a product in hand: a breakdown of nothing is nothing.
            'breakdown' => $productId ? $this->warehouses->breakdown($productId) : null,
            'unallocated' => $productId ? $this->warehouses->unallocated($productId) : null,
        ], 200);
    }

    #[ApiDoc(
        summary: 'Batches about to expire, and those already expired',
        description: 'Only for sellers whose products are tracked in batches. Expiring stock is worth '
            . 'knowing about while it can still be sold; expired stock is worth knowing about because '
            . 'it cannot. Answers empty lists where batches are not in use.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function batches(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;

        if (!Schema::hasTable('product_batches')) {
            return response()->json(['expiring_soon' => [], 'expired' => [], 'within_days' => 0], 200);
        }

        $withinDays = max(1, min((int) $request->query('days', 30), 365));

        return response()->json([
            'within_days' => $withinDays,
            'expiring_soon' => $this->batchRows(
                ProductBatch::where('seller_id', $sellerId)
                    ->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($withinDays)->toDateString()])
                    ->orderBy('expiry_date')
                    ->limit(100)
                    ->get()
            ),
            'expired' => $this->batchRows(
                ProductBatch::where('seller_id', $sellerId)
                    ->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->where('expiry_date', '<', now()->toDateString())
                    ->orderBy('expiry_date')
                    ->limit(100)
                    ->get()
            ),
        ], 200);
    }

    /** Products this seller owns. The only query any of this is allowed to start from. */
    private function ownedProducts(int|string $sellerId)
    {
        return Product::withoutGlobalScope('translate')
            ->where('added_by', 'seller')
            ->where('user_id', $sellerId);
    }

    /**
     * The product_id filter, verified against the seller's catalogue.
     *
     * Returns false — distinct from null — when an id was given that is not theirs, so the caller
     * answers not-found rather than silently widening to the whole shop.
     */
    private function productIdFilter(Request $request, int|string $sellerId): int|null|false
    {
        $productId = $request->query('product_id');

        if ($productId === null || $productId === '') {
            return null;
        }

        return $this->ownedProducts($sellerId)->whereKey($productId)->exists() ? (int) $productId : false;
    }

    private function typeFilter(Request $request): ?string
    {
        $type = $request->query('type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 25), 100));
    }

    /** @return array<int, array<string, mixed>> */
    private function warehousesFor(int|string $sellerId): array
    {
        if (!Schema::hasTable('warehouses')) {
            return [];
        }

        return Warehouse::where('seller_id', $sellerId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'address' => $warehouse->address,
                'is_default' => (bool) $warehouse->is_default,
                'status' => $warehouse->status,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function batchRows($batches): array
    {
        return $batches->map(fn (ProductBatch $batch) => [
            'id' => $batch->id,
            'product_id' => $batch->product_id,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date,
            'quantity' => (int) $batch->quantity,
            'status' => $batch->status,
        ])->values()->all();
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $message]]], 404);
    }
}
