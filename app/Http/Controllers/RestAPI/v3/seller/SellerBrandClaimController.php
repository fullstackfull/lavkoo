<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\BrandClaim;
use App\Models\BrandClaimDocument;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\BrandRegistryService;
use App\Services\Marketplace\SellerPrincipal;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The seller's side of the brand registry.
 *
 * Ordered around the question a seller actually has — which of the brands I already sell is going to
 * become a problem — rather than around the claims table. `exposure` answers that from the
 * catalogue; everything else is how they do something about it.
 */
class SellerBrandClaimController extends Controller
{
    public function __construct(private readonly BrandRegistryService $registry)
    {
    }

    #[ApiDoc(
        summary: 'Which brands I sell under, and where each one stands',
        description: 'Counted from the seller\'s own catalogue rather than from the claims table, so '
            . 'it says something true about their shop. Each row carries how many listings carry the '
            . 'brand, the seller\'s claim on it if they have made one, and whether they may currently '
            . 'list under it. Also whether the marketplace is refusing listings yet or only reporting '
            . 'them — the two are different situations and the screen says which.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function exposure(Request $request): JsonResponse
    {
        return response()->json([
            'enforcing' => $this->registry->isEnforcing(),
            'brands' => $this->registry->brandExposure($request->seller->id),
        ], 200);
    }

    #[ApiDoc(
        summary: 'My claims',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'claims' => $this->registry->claimsFor($request->seller->id)
                ->map(fn (BrandClaim $claim) => $this->present($claim))
                ->all(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Start or rewrite a claim',
        description: 'One claim per seller per brand: a second is an edit of the first, not a second '
            . 'opinion. A claim already with the marketplace cannot be rewritten underneath the person '
            . 'reviewing it — it has to be withdrawn first. A rewritten claim goes back to draft and '
            . 'loses its previous decision, because the previous decision was about different words.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_id' => 'required|integer|exists:brands,id',
            'claim_type' => 'required|in:' . implode(',', BrandClaim::TYPES),
            'statement' => 'nullable|string|max:2000',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $result = $this->registry->draft(
            sellerId: $request->seller->id,
            brandId: (int) $request['brand_id'],
            type: (string) $request['claim_type'],
            statement: $request['statement'],
            expiresAt: $request['expires_at'],
        );

        if (!$result['ok']) {
            return $this->refused($result['reason']);
        }

        return response()->json([
            'message' => translate('brand_claim_saved'),
            'claim' => $this->present($result['claim']),
        ], 201);
    }

    #[ApiDoc(
        summary: 'Attach evidence to a claim',
        description: 'A trademark certificate, a letter of authority, an invoice from the brand. '
            . 'Stored on the private disk under a high-entropy name with a server-controlled '
            . 'extension, and served only through the ownership-checked route below — these are '
            . 'commercially sensitive documents, not public files.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function attach(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:' . implode(',', BrandClaimDocument::TYPES),
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'reference' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $claim = $this->find($request, $id);

        if (!$claim) {
            return $this->notFound();
        }

        $result = $this->registry->attachDocument(
            claim: $claim,
            file: $request->file('file'),
            type: (string) $request['document_type'],
            reference: $request['reference'],
            expiresAt: $request['expires_at'],
        );

        if (!$result['ok']) {
            return $this->refused($result['reason']);
        }

        return response()->json([
            'message' => translate('brand_claim_document_added'),
            'claim' => $this->present($claim->refresh()),
        ], 201);
    }

    #[ApiDoc(
        summary: 'Remove one piece of evidence',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function detach(Request $request, $id, $documentId): JsonResponse
    {
        $claim = $this->find($request, $id);
        $document = $claim?->documents()->where('id', $documentId)->first();

        if (!$claim || !$document) {
            return $this->notFound();
        }

        $result = $this->registry->deleteDocument($document);

        if (!$result['ok']) {
            return $this->refused($result['reason']);
        }

        return response()->json(['message' => translate('brand_claim_document_removed')], 200);
    }

    #[ApiDoc(
        summary: 'Read one piece of evidence back',
        description: 'Streamed from the private disk after the claim is matched to the shop on the '
            . 'token. A document id from another seller\'s claim is not found rather than forbidden.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function document(Request $request, $id, $documentId): StreamedResponse|JsonResponse
    {
        $claim = $this->find($request, $id);
        $document = $claim?->documents()->where('id', $documentId)->first();

        if (!$claim || !$document) {
            return $this->notFound();
        }

        $path = $this->registry->documentPath($document);

        if (!Storage::disk('local')->exists($path)) {
            return $this->notFound();
        }

        return Storage::disk('local')->download($path, $document->original_name ?: $document->file_path);
    }

    #[ApiDoc(
        summary: 'Hand a claim to the marketplace',
        description: 'Refused when the claim has no evidence behind it. A review queue full of empty '
            . 'forms is how the claims with real documents in them stop being read.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function submit(Request $request, $id): JsonResponse
    {
        $claim = $this->find($request, $id);

        if (!$claim) {
            return $this->notFound();
        }

        $result = $this->registry->submit($claim, $this->principal($request));

        if (!$result['ok']) {
            return $this->refused($result['reason']);
        }

        return response()->json([
            'message' => translate('brand_claim_submitted'),
            'claim' => $this->present($claim->refresh()),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Take a claim back',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function withdraw(Request $request, $id): JsonResponse
    {
        $claim = $this->find($request, $id);

        if (!$claim) {
            return $this->notFound();
        }

        $result = $this->registry->withdraw($claim);

        if (!$result['ok']) {
            return $this->refused($result['reason']);
        }

        return response()->json([
            'message' => translate('brand_claim_withdrawn'),
            'claim' => $this->present($claim->refresh()),
        ], 200);
    }

    private function present(BrandClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'brand_id' => $claim->brand_id,
            'brand_name' => $claim->brand?->name,
            'claim_type' => $claim->claim_type,
            'status' => $claim->status,
            'statement' => $claim->statement,
            'is_editable' => $claim->isEditable(),
            'is_pending' => $claim->isPending(),
            'entitles' => $claim->entitles(),
            'submitted_at' => $claim->submitted_at,
            'reviewed_at' => $claim->reviewed_at,
            'review_note' => $claim->review_note,
            'expires_at' => $claim->expires_at,
            'documents' => $claim->documents->map(fn (BrandClaimDocument $document) => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'original_name' => $document->original_name,
                'reference' => $document->reference,
                'expires_at' => $document->expires_at,
                'created_at' => $document->created_at,
            ])->all(),
        ];
    }

    private function find(Request $request, $id): ?BrandClaim
    {
        return BrandClaim::with(['documents', 'brand'])
            ->where('seller_id', $request->seller->id)
            ->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'brand_claim', 'message' => translate('brand_claim_not_found')],
        ]], 404);
    }

    private function refused(string $reason): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'brand_claim', 'message' => translate($reason)],
        ]], 403);
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }
}
