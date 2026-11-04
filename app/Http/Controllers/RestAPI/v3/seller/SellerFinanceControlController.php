<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\FeeSimulatorService;
use App\Services\Marketplace\PricingPolicyService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerReconciliationService;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The seller's own view of the marketplace's arithmetic.
 *
 * Three questions a seller could not previously ask: does what I sold add up to what I was paid,
 * what would this sale cost me, and who changed this price. All three are answered from records
 * that exist — none of them computes a plausible figure where a row is missing.
 */
class SellerFinanceControlController extends Controller
{
    public function __construct(
        private readonly SellerReconciliationService $reconciliation,
        private readonly FeeSimulatorService $simulator,
        private readonly PricingPolicyService $pricing,
    ) {
    }

    #[ApiDoc(
        summary: 'Does what I sold add up to what I was paid',
        description: 'Walks the chain one shop at a time — delivered lines, then an earning recorded '
            . 'for each, then a credit in the ledger for each — and names what fell out at every step. '
            . 'A delivered line with no earning is money earned and not reported; an earning with no '
            . 'credit is money reported and not paid in. Both come with a sample that opens. '
            . '`reconciles` is true only when nothing fell out of either hand-off, not merely when the '
            . 'totals happen to match: a missing earning and an extra credit can cancel out.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function reconciliation(Request $request): JsonResponse
    {
        return response()->json(
            $this->reconciliation->forSeller(
                sellerId: $request->seller->id,
                from: $request->get('from'),
                to: $request->get('to'),
            ),
            200,
        );
    }

    #[ApiDoc(
        summary: 'What would this sale cost me',
        description: 'Runs the marketplace\'s own commission engine — the code that writes the '
            . 'commission snapshot when a real order is placed — against a price the seller is '
            . 'considering, and names the rule that would apply. Tax, shipping and payment processing '
            . 'are listed as excluded rather than estimated: none of them exists for an order nobody '
            . 'has placed, and a plausible number a seller prices against is worse than an honest gap.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function feeSimulator(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'nullable|integer',
            'unit_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1|max:10000',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,flat',
            'category_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        // One of the two has to be present, or there is nothing to simulate: a price typed in, or a
        // product whose price can be read.
        if (!$request->filled('product_id') && !$request->filled('unit_price')) {
            return response()->json(['errors' => [
                ['code' => 'unit_price', 'message' => translate('simulator_needs_a_price_or_a_product')],
            ]], 403);
        }

        return response()->json(
            $this->simulator->simulate(sellerId: $request->seller->id, input: $request->all()),
            200,
        );
    }

    #[ApiDoc(
        summary: 'Who changed this price',
        description: 'Every recorded move of a price or discount on the shop\'s own products, newest '
            . 'first, with what moved it: the seller\'s own form, the panel, a bulk job, a rule, a '
            . 'promotion. Filterable by product and by source. The first price a product was ever '
            . 'given is marked as such rather than shown as a change from nothing.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function priceChanges(Request $request): JsonResponse
    {
        $changes = ProductPriceChange::query()
            // Scoped through the products table as well as on the denormalised seller_id: the column
            // is written by the recorder, and a row that predates it or was written by something
            // else must not become a way to read another shop's pricing history.
            ->whereIn('product_id', DB::table('products')
                ->where(['added_by' => 'seller', 'user_id' => $request->seller->id])
                ->select('id'))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', (int) $request->get('product_id')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->get('source')))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('limit', 25))));

        return response()->json([
            'total_size' => $changes->total(),
            'limit' => $changes->perPage(),
            'offset' => $changes->currentPage(),
            'changes' => collect($changes->items())->map(fn (ProductPriceChange $change) => [
                'id' => $change->id,
                'product_id' => $change->product_id,
                'previous_price' => $change->previous_price === null ? null : (float) $change->previous_price,
                'new_price' => (float) $change->new_price,
                'delta' => $change->delta(),
                'is_first_price' => $change->isFirstPrice(),
                'previous_discount' => $change->previous_discount === null ? null : (float) $change->previous_discount,
                'new_discount' => $change->new_discount === null ? null : (float) $change->new_discount,
                'previous_discount_type' => $change->previous_discount_type,
                'new_discount_type' => $change->new_discount_type,
                'source' => $change->source,
                'reason' => $change->reason,
                'actor_type' => $change->actor_type,
                'actor_name' => $change->actor_name,
                'created_at' => $change->created_at,
            ])->all(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'The floor under your own prices',
        description: 'A margin over recorded cost, an absolute minimum, or both, and whether they are '
            . 'being enforced. Off until the seller turns it on: a floor that started refusing prices '
            . 'the day it shipped would block whatever the shop is already doing on purpose. A margin '
            . 'floor only applies to products with a recorded cost — a product with none has no margin '
            . 'to compute, and the policy says nothing about it rather than inventing a floor of zero.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function pricingPolicy(Request $request): JsonResponse
    {
        $policy = $this->pricing->forSeller($request->seller->id);

        return response()->json([
            'min_margin_percent' => $policy?->min_margin_percent,
            'min_price' => $policy?->min_price,
            'enforce' => (bool) ($policy?->enforce ?? false),
            'binding' => (bool) $policy?->isBinding(),
            // How many of the shop's own products the policy can actually speak about, so a seller
            // switching it on knows whether it covers their catalogue or a corner of it.
            'products_with_a_cost' => Product::withoutGlobalScope('translate')
                ->where(['added_by' => 'seller', 'user_id' => $request->seller->id])
                ->where('purchase_price', '>', 0)
                ->count(),
            'products_total' => Product::withoutGlobalScope('translate')
                ->where(['added_by' => 'seller', 'user_id' => $request->seller->id])
                ->count(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Set the floor under your own prices',
        description: 'Applies to the paths the seller controls — their own price edits, bulk price '
            . 'changes and their automation rules. An admin correction, an import or a marketplace '
            . 'promotion is not refused by it: those stay covered by the below-cost detector, which '
            . 'is the honest division between refusing where the seller is acting and reporting '
            . 'everywhere else.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function savePricingPolicy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'min_margin_percent' => 'nullable|numeric|min:0|max:1000',
            'min_price' => 'nullable|numeric|min:0',
            'enforce' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $this->pricing->save($this->principal($request), $validator->validated());

        return response()->json(['message' => translate('pricing_policy_saved')], 200);
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }
}
