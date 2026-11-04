<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Models\SellerVerificationDocument;
use App\Services\Marketplace\SellerVerificationService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin KYC review (Phase 3, Stage A). A queue of submitted seller documents and a per-seller
 * standing derived by SellerVerificationService, plus the two settings that arm the payout gate.
 *
 * The controller carries requests in; the service owns the state changes, the audit lines, and the
 * status derivation. It never writes to the sellers table — KYC is a derived dimension, so a
 * seller's account status is left exactly as the existing approval flow set it.
 */
class SellerVerificationController extends BaseController
{
    public function __construct(private readonly SellerVerificationService $verification)
    {
    }

    /**
     * Stream a KYC document from the private disk. The admin middleware already restricts this to
     * admins — this is the only way the file is reachable now that KYC is no longer on the public disk.
     */
    public function document(int $id)
    {
        $doc = SellerVerificationDocument::findOrFail($id);
        abort_if(!$doc->file_path, 404);
        $path = 'seller/kyc/' . $doc->file_path;
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path);
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $documents = collect();
        $sellers = collect();
        $statusFilter = $request?->input('status');

        if (Schema::hasTable('seller_verification_documents')) {
            $query = SellerVerificationDocument::query()->orderByDesc('created_at')->orderByDesc('id');
            if ($statusFilter) {
                $query->where('status', $statusFilter);
            }
            $documents = $query->paginate(20)->withQueryString();

            // The sellers referenced on this page, so the view can show names without N+1 lookups.
            $sellerIds = collect($documents->items())->pluck('seller_id')->unique();
            if (Schema::hasTable('sellers') && $sellerIds->isNotEmpty()) {
                $sellers = Seller::whereIn('id', $sellerIds)->get()->keyBy('id');
            }
        }

        // Overall KYC standing per seller shown on this page — derived, not stored.
        $standing = [];
        foreach ($sellers as $id => $seller) {
            $standing[$id] = $this->verification->overallStatus($id);
        }

        return view('admin-views.marketplace.seller-verification', [
            'documents' => $documents,
            'sellers' => $sellers,
            'standing' => $standing,
            'statusFilter' => $statusFilter,
            'requiredDocuments' => $this->verification->requiredDocumentTypes(),
            'kycRequiredForPayout' => $this->verification->isKycRequiredForPayout(),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|integer',
            'expires_at' => 'nullable|date',
            'note' => 'nullable|string|max:512',
        ]);

        $doc = SellerVerificationDocument::find($validated['document_id']);
        if (!$doc) {
            ToastMagic::error(translate('document_not_found'));
            return back();
        }

        $this->verification->approve($doc, auth('admin')->id(), $validated['expires_at'] ?? null, $validated['note'] ?? null);
        ToastMagic::success(translate('document_approved'));

        return back();
    }

    public function reject(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|integer',
            'rejection_reason' => 'required|string|max:512',
        ]);

        $doc = SellerVerificationDocument::find($validated['document_id']);
        if (!$doc) {
            ToastMagic::error(translate('document_not_found'));
            return back();
        }

        $this->verification->reject($doc, auth('admin')->id(), $validated['rejection_reason']);
        ToastMagic::success(translate('document_rejected'));

        return back();
    }

    /**
     * Arm or disarm the payout gate and set the required-document list. These two settings are the
     * only thing that changes behaviour for sellers — everything else is a passive record — so they
     * live behind this one deliberate save.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'require_kyc_for_payout' => 'nullable|boolean',
            'kyc_required_documents' => 'nullable|string|max:1000',
        ]);

        $documents = array_values(array_filter(array_map('trim', explode(',', $validated['kyc_required_documents'] ?? ''))));

        BusinessSetting::updateOrCreate(['type' => 'require_kyc_for_payout'], ['value' => (bool) ($validated['require_kyc_for_payout'] ?? false) ? '1' : '0']);
        BusinessSetting::updateOrCreate(['type' => 'kyc_required_documents'], ['value' => implode(',', $documents)]);

        cache()->flush();
        ToastMagic::success(translate('kyc_settings_updated'));

        return back();
    }
}
