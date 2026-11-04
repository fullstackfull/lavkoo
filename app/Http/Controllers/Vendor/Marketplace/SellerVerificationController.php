<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Marketplace\SellerVerificationService;
use App\Utils\ImageManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The seller's own KYC screen (Phase 3, Stage A): see the required documents, what has been
 * submitted, each document's review state and any rejection reason, and submit a new one.
 *
 * Identity always comes from auth('seller') — a seller can only ever act on their own documents,
 * never a supplied id.
 */
class SellerVerificationController extends BaseController
{
    public function __construct(private readonly SellerVerificationService $verification)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        return view('vendor-views.marketplace.seller-verification', [
            'documents' => $this->verification->documentsFor($sellerId),
            'requiredDocuments' => $this->verification->requiredDocumentTypes(),
            'overallStatus' => $this->verification->overallStatus($sellerId),
            'kycRequiredForPayout' => $this->verification->isKycRequiredForPayout(),
        ]);
    }

    /**
     * Stream one of the signed-in seller's own KYC documents from the private disk — scoped by
     * seller_id, so a seller can never fetch another shop's document by id.
     */
    public function document(int $id)
    {
        $doc = \App\Models\SellerVerificationDocument::where(['id' => $id, 'seller_id' => auth('seller')->id()])->firstOrFail();
        abort_if(!$doc->file_path, 404);
        $path = 'seller/kyc/' . $doc->file_path;
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|string|max:60',
            'document_number' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date',
            'document_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        // KYC documents (identity / business licence) go to the PRIVATE 'local' disk with a
        // high-entropy name, never the public default disk, and are served only through the
        // ownership-checked document() route — so they can't be fetched by guessing a public URL.
        $filePath = $request->hasFile('document_file')
            ? $this->verification->storeDocumentFile($request->file('document_file'))
            : null;

        $this->verification->submit(
            sellerId: auth('seller')->id(),
            type: $validated['document_type'],
            number: $validated['document_number'] ?? null,
            filePath: $filePath,
            expiresAt: $validated['expires_at'] ?? null,
        );

        ToastMagic::success(translate('document_submitted_for_review'));

        return back();
    }
}
