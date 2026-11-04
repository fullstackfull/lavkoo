<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\SellerVerificationDocument;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerVerificationService;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller KYC over the v3 seller API — the mobile twin of the web
 * Vendor\Marketplace\SellerVerificationController: same service, same private
 * storage, same ownership rules.
 */
class SellerVerificationController extends Controller
{
    public function __construct(private readonly SellerVerificationService $verification)
    {
    }

    #[ApiDoc(
        summary: "The seller's KYC documents, required types and overall status",
        description: 'Admin review internals (reviewer id, internal notes, the private-disk filename) are never '
            . 'serialized; a document reports has_file instead of its path.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;

        return response()->json([
            'overall_status' => $this->verification->overallStatus($sellerId),
            'kyc_required_for_payout' => $this->verification->isKycRequiredForPayout(),
            'required_documents' => $this->verification->requiredDocumentTypes(),
            'documents' => $this->verification->documentsFor($sellerId)->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Submit a KYC document for review',
        description: 'multipart/form-data: document_type (required), document_number, expires_at, document_file '
            . '(pdf/jpg/jpeg/png, max 5 MB). Files are stored on the private disk under a high-entropy name and '
            . 'are only ever served through the ownership-checked document route.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        emits: ['kyc_submitted'],
        group: 'vendors',
    )]
    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|max:60',
            'document_number' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date',
            'document_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $filePath = $request->hasFile('document_file')
            ? $this->verification->storeDocumentFile($request->file('document_file'))
            : null;

        $document = $this->verification->submit(
            sellerId: $request->seller->id,
            type: $request['document_type'],
            number: $request['document_number'] ?? null,
            filePath: $filePath,
            expiresAt: $request['expires_at'] ?? null,
        );

        return response()->json([
            'message' => translate('document_submitted_for_review'),
            'document' => $document,
        ], 200);
    }

    /**
     * Stream one of the token's own KYC documents from the private disk — scoped by seller_id,
     * so a seller can never fetch another shop's document by id.
     */
    #[ApiDoc(
        summary: "Stream one of the seller's own KYC documents",
        description: 'Scoped by seller_id, so a seller can never fetch another shop\'s document by id. '
            . 'Returns the file stream, or 404 when the document is missing, has no file, or is not theirs.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function document(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $document = SellerVerificationDocument::where(['id' => $id, 'seller_id' => $request->seller->id])->first();
        $path = $document?->file_path ? 'seller/kyc/' . $document->file_path : null;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => translate('file_not_found')], 404);
        }

        return Storage::disk('local')->response($path);
    }
}
