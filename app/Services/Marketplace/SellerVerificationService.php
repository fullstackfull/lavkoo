<?php

namespace App\Services\Marketplace;

use App\Models\SellerVerificationDocument;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a seller's KYC standing and payout eligibility (Phase 3, Stage A).
 *
 * Two rules run through everything here:
 *
 *   1. **Derived, not stored.** A seller's overall KYC status is computed from their documents
 *      against a configurable required set. The account's own `status` column is never touched, so
 *      adopting this changes no existing behaviour.
 *
 *   2. **Off by default.** Payout gating only bites when an admin turns on `require_kyc_for_payout`.
 *      Until then `isPayoutEligible()` is always true and the payout flow behaves exactly as before —
 *      which is what keeps this backward compatible with every current install and the Flutter apps.
 */
class SellerVerificationService
{
    /** The required set an install ships with when the admin has configured none. */
    public const DEFAULT_REQUIRED_DOCUMENTS = ['identity', 'business_license'];

    /** The document extensions a seller may upload. Server-controlled — the client extension is
     *  mapped onto this whitelist, never trusted, so an upload can't smuggle an executable one. */
    public const ALLOWED_FILE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_VERIFIED = 'verified';

    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * The document types a seller must have approved to be considered verified.
     *
     * Read from the `kyc_required_documents` setting (a comma-separated list) so a market can define
     * its own set; falls back to a sensible default. An explicitly empty setting means "require
     * nothing", which is honoured — that is how an operator opts a whole store out of documents while
     * still using the screens.
     */
    public function requiredDocumentTypes(): array
    {
        $configured = $this->setting('kyc_required_documents');

        if ($configured === null) {
            return self::DEFAULT_REQUIRED_DOCUMENTS;
        }

        if (is_array($configured)) {
            $list = $configured;
        } else {
            $list = explode(',', (string) $configured);
        }

        return array_values(array_filter(array_map('trim', $list)));
    }

    /** Every document a seller has submitted, newest first. */
    public function documentsFor(int|string $sellerId): Collection
    {
        if (!Schema::hasTable('seller_verification_documents')) {
            return collect();
        }

        return SellerVerificationDocument::where('seller_id', $sellerId)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * The seller's overall KYC standing, derived from their documents:
     *
     *   - not_required : nothing is required of anyone (empty required set)
     *   - verified     : every required type has an approved, unexpired document
     *   - rejected     : a required type's most recent document was rejected and none is approved —
     *                    the seller needs to resubmit
     *   - pending      : something is submitted and under review, but not everything is approved yet
     *   - unverified   : a required type has nothing submitted at all
     *
     * The order matters: rejected and pending only surface once the seller has actually engaged; a
     * seller who has submitted nothing is unverified, not rejected.
     */
    public function overallStatus(int|string $sellerId, ?\DateTimeInterface $now = null): string
    {
        $required = $this->requiredDocumentTypes();
        if ($required === []) {
            return self::STATUS_NOT_REQUIRED;
        }

        $byType = $this->documentsFor($sellerId)->groupBy('document_type');

        $anyMissing = false;
        $anyRejected = false;
        $anyPending = false;

        foreach ($required as $type) {
            $docs = $byType->get($type) ?? collect();

            if ($docs->isEmpty()) {
                $anyMissing = true;
                continue;
            }

            // Satisfied if ANY document of this type is effectively approved (unexpired).
            if ($docs->contains(fn ($d) => $d->isEffectivelyApproved($now))) {
                continue;
            }

            // Not satisfied — classify by the most recent document's state.
            $latest = $docs->first();
            if ($latest->status === SellerVerificationDocument::STATUS_REJECTED) {
                $anyRejected = true;
            } elseif ($latest->status === SellerVerificationDocument::STATUS_PENDING) {
                $anyPending = true;
            } else {
                // approved-but-expired, or an 'expired' row: treat as missing/needs-resubmission.
                $anyMissing = true;
            }
        }

        if (!$anyMissing && !$anyRejected && !$anyPending) {
            return self::STATUS_VERIFIED;
        }
        if ($anyRejected) {
            return self::STATUS_REJECTED;
        }
        if ($anyPending) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_UNVERIFIED;
    }

    public function isVerified(int|string $sellerId, ?\DateTimeInterface $now = null): bool
    {
        $status = $this->overallStatus($sellerId, $now);

        return $status === self::STATUS_VERIFIED || $status === self::STATUS_NOT_REQUIRED;
    }

    /** Is KYC a hard requirement for withdrawing money? Off unless an admin turns it on. */
    public function isKycRequiredForPayout(): bool
    {
        return (bool) $this->setting('require_kyc_for_payout');
    }

    /**
     * Store an uploaded KYC document on the PRIVATE 'local' disk under a high-entropy name and
     * return the bare filename to persist. Shared by the web and API controllers so both surfaces
     * store documents identically; serving always goes through an ownership-checked route.
     */
    public function storeDocumentFile(UploadedFile $file): string
    {
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($clientExtension, self::ALLOWED_FILE_EXTENSIONS, true) ? $clientExtension : 'pdf';
        $fileName = date('Y-m-d') . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
        Storage::disk('local')->put('seller/kyc/' . $fileName, file_get_contents($file));

        return $fileName;
    }

    /**
     * The gate the payout flow asks about. When KYC is not required for payout this is always true,
     * so the withdrawal path is unchanged; when it is required, only a verified seller may withdraw.
     */
    public function isPayoutEligible(int|string $sellerId, ?\DateTimeInterface $now = null): bool
    {
        if (!$this->isKycRequiredForPayout()) {
            return true;
        }

        return $this->isVerified($sellerId, $now);
    }

    // ---- write paths (each records an audit line) ----

    public function submit(int|string $sellerId, string $type, ?string $number = null, ?string $filePath = null, ?string $expiresAt = null): SellerVerificationDocument
    {
        $doc = SellerVerificationDocument::create([
            'seller_id' => $sellerId,
            'document_type' => $type,
            'document_number' => $number,
            'file_path' => $filePath,
            'status' => SellerVerificationDocument::STATUS_PENDING,
            'expires_at' => $expiresAt ?: null,
        ]);

        $this->audit?->record(
            action: 'seller.kyc_submitted',
            subject: ['type' => 'seller', 'id' => $sellerId],
            after: ['document_id' => $doc->id, 'document_type' => $type],
        );

        try {
            app(\App\Services\Analytics\Analytics::class)->kycSubmitted(
                sellerId: (int) $sellerId,
                documentId: (int) $doc->id,
                documentType: $type,
            );
        } catch (\Throwable) {
            // Verification progress must not depend on analytics being up.
        }

        return $doc;
    }

    public function approve(SellerVerificationDocument $doc, int|string|null $reviewer = null, ?string $expiresAt = null, ?string $note = null): bool
    {
        $doc->status = SellerVerificationDocument::STATUS_APPROVED;
        $doc->reviewed_by = $reviewer;
        $doc->reviewed_at = now();
        $doc->rejection_reason = null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $doc->expires_at = $expiresAt;
        }
        if ($note !== null) {
            $doc->notes = $note;
        }
        $ok = $doc->save();

        $this->audit?->record(
            action: 'seller.kyc_approved',
            subject: ['type' => 'seller', 'id' => $doc->seller_id],
            after: ['document_id' => $doc->id, 'document_type' => $doc->document_type],
        );

        return $ok;
    }

    public function reject(SellerVerificationDocument $doc, int|string|null $reviewer = null, ?string $reason = null): bool
    {
        $doc->status = SellerVerificationDocument::STATUS_REJECTED;
        $doc->reviewed_by = $reviewer;
        $doc->reviewed_at = now();
        $doc->rejection_reason = $reason;
        $ok = $doc->save();

        $this->audit?->record(
            action: 'seller.kyc_rejected',
            subject: ['type' => 'seller', 'id' => $doc->seller_id],
            after: ['document_id' => $doc->id, 'reason' => $reason],
        );

        return $ok;
    }

    private function setting(string $name): mixed
    {
        try {
            return getWebConfig(name: $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
