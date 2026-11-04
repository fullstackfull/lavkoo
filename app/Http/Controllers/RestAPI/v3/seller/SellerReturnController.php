<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\Product;
use App\Models\ReturnShipment;
use App\Models\VendorLedgerEntry;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\Marketplace\SellerPrincipal;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The goods coming back, and what they did to the seller's balance.
 *
 * A refund has always been visible to sellers as money. The units were not visible at all: nothing
 * recorded that a physical product was on its way back, so nothing restocked it, and a seller who
 * refunded a customer quietly lost the stock as well as the sale. Approving a refund now opens a
 * return, and this is where the seller follows it: in transit, received — restocked or not — or
 * rejected because what arrived was not sellable.
 *
 * Each return also carries the ledger lines the refund produced: the debit for the money returned
 * and the credit giving back commission on the refunded portion. That second line is the one sellers
 * never see and most doubt exists.
 */
class SellerReturnController extends Controller
{
    public function __construct(private readonly ReturnLogisticsService $returns)
    {
    }

    #[ApiDoc(
        summary: 'Returns coming back to this seller',
        description: 'The seller\'s return shipments, newest first, filterable by status: authorized, '
            . 'in_transit, received, restocked, rejected. Each carries the product, the quantity, the '
            . 'reason and where it has got to. Only this seller\'s returns are ever listed.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('return_shipments')) {
            return response()->json(['total_size' => 0, 'limit' => 25, 'offset' => 1, 'returns' => [], 'statuses' => $this->statuses()], 200);
        }

        $returns = ReturnShipment::where('seller_id', $request->seller->id)
            ->when($this->statusFilter($request), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(perPage: $this->limit($request), page: $this->page($request));

        $names = $this->productNames(collect($returns->items())->pluck('product_id')->filter()->all(), $request->seller->id);

        return response()->json([
            'total_size' => $returns->total(),
            'limit' => $returns->perPage(),
            'offset' => $returns->currentPage(),
            'statuses' => $this->statuses(),
            'returns' => collect($returns->items())
                ->map(fn (ReturnShipment $rma) => $this->summary($rma, $names))->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'One return, with what the refund did to the balance',
        description: 'The return itself plus the ledger lines the refund produced — the debit for the '
            . 'money returned to the customer and the credit giving back commission on the refunded '
            . 'portion. A return belonging to another seller answers 404, the same as one that does '
            . 'not exist.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function show(Request $request, $id): JsonResponse
    {
        $rma = $this->returnFor($request, $id);

        if (!$rma) {
            return $this->notFound();
        }

        $names = $this->productNames([$rma->product_id], $request->seller->id);

        return response()->json([
            'return' => $this->summary($rma, $names) + [
                'carrier' => $rma->carrier,
                'tracking_number' => $rma->tracking_number,
                'note' => $rma->note,
                'order_id' => $rma->order_id,
                'refund_request_id' => $rma->refund_request_id,
            ],
            'ledger' => $this->ledgerFor($rma, $request->seller->id),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Record that the goods are on their way',
        description: 'Moves an authorized return to in_transit, optionally recording the carrier and '
            . 'tracking number the customer gave. Only an authorized return can be marked in transit; '
            . 'anything else answers 422 saying so.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function markInTransit(Request $request, $id): JsonResponse
    {
        $rma = $this->returnFor($request, $id);

        if (!$rma) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        return $this->answer(
            $this->returns->markInTransit($rma, $request['carrier'], $request['tracking_number']),
            $request,
        );
    }

    #[ApiDoc(
        summary: 'Receive the returned goods',
        description: 'Marks the return received. Where the return is restockable and names a product, '
            . 'the units go back into stock under a row lock and a `return` movement is written to the '
            . 'same ledger a purchase receipt writes to — so a restocked return is explained rather '
            . 'than appearing as an unexplained jump. Pass restock=false when what arrived cannot be '
            . 'sold again.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function receive(Request $request, $id): JsonResponse
    {
        $rma = $this->returnFor($request, $id);

        if (!$rma) {
            return $this->notFound();
        }

        // The decision belongs at receipt, not at authorisation: nobody knows whether goods are
        // sellable until they are looked at.
        if ($request->has('restock')) {
            $rma->update(['restock' => filter_var($request['restock'], FILTER_VALIDATE_BOOLEAN)]);
        }

        $principal = $this->principal($request);

        return $this->answer(
            $this->returns->receive(
                rma: $rma,
                by: $principal->actorId(),
                byType: $principal->actorType(),
            ),
            $request,
        );
    }

    #[ApiDoc(
        summary: 'Refuse a return',
        description: 'Closes the return as rejected, with the reason recorded. Use this when what came '
            . 'back is not what was sent or cannot be accepted. A reason is required — a refusal a '
            . 'customer cannot be told the grounds for is not a decision anyone can act on.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function reject(Request $request, $id): JsonResponse
    {
        $rma = $this->returnFor($request, $id);

        if (!$rma) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:255']);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        return $this->answer($this->returns->reject($rma, $request['reason']), $request);
    }

    /**
     * Turn a service result into an answer.
     *
     * A refusal here is a state problem — receiving a return that is already closed — so it answers
     * 422 with what was wrong rather than a bare failure the client has to guess at.
     */
    private function answer(array $result, Request $request): JsonResponse
    {
        if (!($result['ok'] ?? false)) {
            return response()->json(['errors' => [
                ['code' => 'return', 'message' => translate($result['reason'] ?? 'return_could_not_be_updated')],
            ]], 422);
        }

        $rma = $result['return'] ?? null;
        $names = $rma ? $this->productNames([$rma->product_id], $request->seller->id) : [];

        return response()->json([
            'message' => translate('return_updated'),
            'return' => $rma ? $this->summary($rma, $names) : null,
            'restocked' => $result['restocked'] ?? null,
            'balance_after' => $result['balance_after'] ?? null,
        ], 200);
    }

    private function returnFor(Request $request, $id): ?ReturnShipment
    {
        if (!Schema::hasTable('return_shipments')) {
            return null;
        }

        // Scoped on the seller, so another shop's return is not found rather than actionable.
        return ReturnShipment::where('seller_id', $request->seller->id)->find($id);
    }

    /**
     * The ledger lines the refund produced.
     *
     * The commission credit is the one sellers never see: a refund used to leave the marketplace's
     * cut of a sale it gave back still charged. Showing it is how a seller can tell it happened.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledgerFor(ReturnShipment $rma, int|string $sellerId): array
    {
        if (!Schema::hasTable('vendor_ledger_entries') || !$rma->order_details_id) {
            return [];
        }

        return VendorLedgerEntry::where('seller_id', $sellerId)
            ->where('reference_type', 'order_details')
            ->where('reference_id', $rma->order_details_id)
            ->whereIn('entry_type', [VendorLedgerEntry::TYPE_REFUND, VendorLedgerEntry::TYPE_COMMISSION_CHARGE])
            ->orderBy('id')
            ->get()
            ->map(fn (VendorLedgerEntry $entry) => [
                'entry_type' => $entry->entry_type,
                'credit' => round((float) $entry->credit, 2),
                'debit' => round((float) $entry->debit, 2),
                'status' => $entry->status,
                'description' => $entry->description,
                'created_at' => $entry->created_at,
            ])->values()->all();
    }

    /**
     * Product names for the ids in a page, looked up once.
     *
     * Scoped to the seller like everything else: a return should never be the way a product name
     * from another shop reaches this one.
     *
     * @param  array<int, int|string|null>  $productIds
     * @return array<int, string>
     */
    private function productNames(array $productIds, int|string $sellerId): array
    {
        $productIds = array_values(array_filter($productIds));

        if ($productIds === []) {
            return [];
        }

        return Product::withoutGlobalScope('translate')
            ->where('added_by', 'seller')
            ->where('user_id', $sellerId)
            ->whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function summary(ReturnShipment $rma, array $names): array
    {
        return [
            'id' => $rma->id,
            'reference' => $rma->reference,
            'status' => $rma->status,
            'product_id' => $rma->product_id,
            'product_name' => $names[$rma->product_id] ?? null,
            'qty' => (int) $rma->qty,
            'reason' => $rma->reason,
            'restock' => (bool) $rma->restock,
            'is_open' => $rma->isOpen(),
            'received_at' => $rma->received_at,
            'created_at' => $rma->created_at,
        ];
    }

    /** @return array<int, string> */
    private function statuses(): array
    {
        return [
            ReturnShipment::STATUS_AUTHORIZED,
            ReturnShipment::STATUS_IN_TRANSIT,
            ReturnShipment::STATUS_RECEIVED,
            ReturnShipment::STATUS_RESTOCKED,
            ReturnShipment::STATUS_REJECTED,
        ];
    }

    private function statusFilter(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) && in_array($status, $this->statuses(), true) ? $status : null;
    }

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 25), 100));
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('offset', 1));
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'return', 'message' => translate('return_not_found')],
        ]], 404);
    }
}
