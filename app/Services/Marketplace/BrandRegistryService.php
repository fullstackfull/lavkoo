<?php

namespace App\Services\Marketplace;

use App\Models\BrandClaim;
use App\Models\BrandClaimDocument;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Who may sell under a brand.
 *
 * The registry answers one question — `mayList($sellerId, $brandId)` — and everything else exists to
 * make that answer defensible. Three properties matter.
 *
 * A brand nobody has claimed is open. Turning the registry on must not empty the storefront: nine
 * brands exist here and four products carry one, none of them claimed by anybody, and a gate that
 * refused everything unclaimed would take a working marketplace offline on the day it shipped. So an
 * unclaimed brand behaves exactly as it did before the registry existed.
 *
 * A brand somebody has claimed is closed to everybody else. That is the whole point: once a seller
 * has proved to a person that they own a name, a second seller listing under it is the thing the
 * registry is for.
 *
 * And the gate is advisory until the marketplace turns it on. `brand_enforcement` starts off, so the
 * registry can be populated, claims can be reviewed, and the mismatches can be *seen* — through the
 * detector, which is honest about what it found — before a single seller is refused a listing they
 * were making yesterday. Switching from advisory to enforcing is a decision somebody makes with the
 * list in front of them, not a side effect of deploying this file.
 */
class BrandRegistryService
{
    /** The setting that turns refusal on. Off means the mismatches are reported, not blocked. */
    public const ENFORCEMENT_SETTING = 'brand_claim_enforcement';

    public const ALLOWED_FILE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Evidence lives on the private disk, like KYC — these are commercially sensitive documents. */
    private const DOCUMENT_PATH = 'seller/brand-claims/';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * May this seller list a product under this brand?
     *
     * A product with no brand is always allowed — most of the catalogue has none, and requiring a
     * claim to sell an unbranded thing would be nonsense.
     */
    public function mayList(int|string $sellerId, int|string|null $brandId): bool
    {
        if (!$brandId || !Schema::hasTable('brand_claims')) {
            return true;
        }

        $claims = BrandClaim::where('brand_id', $brandId)
            ->where('status', BrandClaim::STATUS_APPROVED)
            ->get();

        $entitled = $claims->filter(fn (BrandClaim $claim) => $claim->entitles());

        if ($entitled->isEmpty()) {
            // Nobody holds this brand, including nobody whose approval has lapsed. Open, as it was
            // before the registry existed.
            return true;
        }

        return $entitled->contains(fn (BrandClaim $claim) => (int) $claim->seller_id === (int) $sellerId);
    }

    /** Is the marketplace refusing listings yet, or only reporting them? */
    public function isEnforcing(): bool
    {
        if (!Schema::hasTable('business_settings')) {
            return false;
        }

        $value = DB::table('business_settings')->where('type', self::ENFORCEMENT_SETTING)->value('value');

        return in_array((string) $value, ['1', 'true', 'on'], true);
    }

    /**
     * The seller's own claims, with what stands behind each.
     *
     * @return Collection<int, BrandClaim>
     */
    public function claimsFor(int|string $sellerId): Collection
    {
        if (!Schema::hasTable('brand_claims')) {
            return collect();
        }

        return BrandClaim::with(['documents', 'brand'])
            ->where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Brands this seller is currently listing under, and where each one stands.
     *
     * This is the view that makes the registry usable rather than bureaucratic: a seller does not
     * want a form, they want to know which of the brands they already sell are going to become a
     * problem. Counted from the catalogue, so it says something true about their shop rather than
     * about the claims table.
     *
     * @return array<int, array{brand_id: int, brand_name: string|null, products: int, claim_status: string|null, may_list: bool}>
     */
    public function brandExposure(int|string $sellerId): array
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('brands')) {
            return [];
        }

        $rows = DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->where(['products.added_by' => 'seller', 'products.user_id' => $sellerId])
            ->whereNotNull('products.brand_id')
            ->groupBy('products.brand_id', 'brands.name')
            ->selectRaw('products.brand_id as brand_id, brands.name as brand_name, COUNT(*) as products')
            ->orderByDesc('products')
            ->get();

        $ownClaims = Schema::hasTable('brand_claims')
            ? BrandClaim::where('seller_id', $sellerId)->get()->keyBy('brand_id')
            : collect();

        return $rows->map(fn ($row) => [
            'brand_id' => (int) $row->brand_id,
            'brand_name' => $row->brand_name,
            'products' => (int) $row->products,
            'claim_status' => $ownClaims->get($row->brand_id)?->status,
            'may_list' => $this->mayList($sellerId, $row->brand_id),
        ])->all();
    }

    /**
     * Start or rewrite a claim.
     *
     * One claim per seller per brand: a second is an edit of the first, not a second opinion. A
     * claim already with the marketplace cannot be rewritten underneath the person reviewing it.
     *
     * @return array{ok: bool, reason?: string, claim?: BrandClaim}
     */
    public function draft(int|string $sellerId, int|string $brandId, string $type, ?string $statement, ?string $expiresAt = null): array
    {
        if (!in_array($type, BrandClaim::TYPES, true)) {
            return ['ok' => false, 'reason' => 'brand_claim_unknown_type'];
        }

        $existing = BrandClaim::where(['seller_id' => $sellerId, 'brand_id' => $brandId])->first();

        if ($existing && !$existing->isEditable()) {
            return ['ok' => false, 'reason' => 'brand_claim_already_with_the_marketplace'];
        }

        $claim = $existing ?? new BrandClaim(['seller_id' => $sellerId, 'brand_id' => $brandId]);

        $claim->forceFill([
            'seller_id' => $sellerId,
            'brand_id' => $brandId,
            'claim_type' => $type,
            'statement' => $statement,
            'expires_at' => $expiresAt ?: null,
            'status' => BrandClaim::STATUS_DRAFT,
            // A rewritten claim is a new claim. The previous decision was about different words and
            // possibly different documents.
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_note' => null,
        ])->save();

        return ['ok' => true, 'claim' => $claim->refresh()];
    }

    /**
     * Hand a claim to the marketplace.
     *
     * Refuses a claim with nothing behind it. A claim with no evidence is a form somebody has to
     * open, read and reject, and a review queue full of those is how the ones with real documents
     * in them stop being read.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function submit(BrandClaim $claim, SellerPrincipal $principal): array
    {
        if (!$claim->isEditable()) {
            return ['ok' => false, 'reason' => 'brand_claim_already_with_the_marketplace'];
        }

        if ($claim->documents()->count() === 0) {
            return ['ok' => false, 'reason' => 'brand_claim_needs_evidence'];
        }

        $claim->forceFill([
            'status' => BrandClaim::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'seller.brand_claim_submitted',
            subject: ['type' => 'brand_claim', 'id' => $claim->id],
            context: [
                'seller_id' => $claim->seller_id,
                'brand_id' => $claim->brand_id,
                'claim_type' => $claim->claim_type,
                'actor' => $principal->actorLabel(),
            ],
        );

        return ['ok' => true];
    }

    /** Take a claim back off the marketplace's desk. */
    public function withdraw(BrandClaim $claim): array
    {
        if (!$claim->isPending()) {
            return ['ok' => false, 'reason' => 'brand_claim_not_pending'];
        }

        $claim->forceFill(['status' => BrandClaim::STATUS_DRAFT, 'submitted_at' => null])->save();

        return ['ok' => true];
    }

    /**
     * Attach evidence.
     *
     * The extension is mapped onto a server-controlled whitelist rather than trusted, so an upload
     * cannot smuggle an executable one — the same rule the KYC uploads already follow.
     */
    public function attachDocument(BrandClaim $claim, UploadedFile $file, string $type, ?string $reference = null, ?string $expiresAt = null): array
    {
        if (!in_array($type, BrandClaimDocument::TYPES, true)) {
            return ['ok' => false, 'reason' => 'brand_claim_unknown_document_type'];
        }

        if (!$claim->isEditable()) {
            return ['ok' => false, 'reason' => 'brand_claim_already_with_the_marketplace'];
        }

        $clientExtension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($clientExtension, self::ALLOWED_FILE_EXTENSIONS, true) ? $clientExtension : 'pdf';
        $fileName = date('Y-m-d') . '-' . bin2hex(random_bytes(16)) . '.' . $extension;

        Storage::disk('local')->put(self::DOCUMENT_PATH . $fileName, file_get_contents($file));

        $document = BrandClaimDocument::create([
            'brand_claim_id' => $claim->id,
            'seller_id' => $claim->seller_id,
            'document_type' => $type,
            'file_path' => $fileName,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 191),
            'reference' => $reference,
            'expires_at' => $expiresAt ?: null,
        ]);

        return ['ok' => true, 'document' => $document];
    }

    public function deleteDocument(BrandClaimDocument $document): array
    {
        if (!$document->claim?->isEditable()) {
            return ['ok' => false, 'reason' => 'brand_claim_already_with_the_marketplace'];
        }

        Storage::disk('local')->delete(self::DOCUMENT_PATH . $document->file_path);
        $document->delete();

        return ['ok' => true];
    }

    /** Where the evidence actually lives, for the ownership-checked route that serves it. */
    public function documentPath(BrandClaimDocument $document): string
    {
        return self::DOCUMENT_PATH . $document->file_path;
    }

    // ---- the marketplace's side ----

    /**
     * A person's decision, recorded as theirs.
     *
     * Approving one seller's ownership claim does not silently revoke anybody else's — that would
     * make a review a two-party decision taken with only one party's paperwork on the desk. What it
     * does do is make the conflict visible, which is what the returned `conflicts` is for.
     */
    public function approve(BrandClaim $claim, int|string|null $reviewer, ?string $note = null, ?string $expiresAt = null): array
    {
        $claim->forceFill([
            'status' => BrandClaim::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer,
            'review_note' => $note,
            'expires_at' => $expiresAt ?: $claim->expires_at,
        ])->save();

        $this->audit->record(
            action: 'marketplace.brand_claim_approved',
            subject: ['type' => 'brand_claim', 'id' => $claim->id],
            context: ['seller_id' => $claim->seller_id, 'brand_id' => $claim->brand_id, 'reviewer' => $reviewer],
        );

        return ['ok' => true, 'conflicts' => $this->otherApprovedClaims($claim)];
    }

    public function reject(BrandClaim $claim, int|string|null $reviewer, ?string $note = null): array
    {
        $claim->forceFill([
            'status' => BrandClaim::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer,
            'review_note' => $note,
        ])->save();

        $this->audit->record(
            action: 'marketplace.brand_claim_rejected',
            subject: ['type' => 'brand_claim', 'id' => $claim->id],
            context: ['seller_id' => $claim->seller_id, 'brand_id' => $claim->brand_id, 'reviewer' => $reviewer],
        );

        return ['ok' => true];
    }

    /**
     * Withdraw an approval that has already been given.
     *
     * Separate from rejection because it means something different: rejection is "we never agreed",
     * revocation is "we did and we no longer do". A seller reading their own history is entitled to
     * that distinction, and so is anybody investigating a dispute later.
     */
    public function revoke(BrandClaim $claim, int|string|null $reviewer, ?string $note = null): array
    {
        if ($claim->status !== BrandClaim::STATUS_APPROVED) {
            return ['ok' => false, 'reason' => 'brand_claim_not_approved'];
        }

        $claim->forceFill([
            'status' => BrandClaim::STATUS_REVOKED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer,
            'review_note' => $note,
        ])->save();

        $this->audit->record(
            action: 'marketplace.brand_claim_revoked',
            subject: ['type' => 'brand_claim', 'id' => $claim->id],
            context: ['seller_id' => $claim->seller_id, 'brand_id' => $claim->brand_id, 'reviewer' => $reviewer],
        );

        return ['ok' => true];
    }

    /**
     * How many listings a decision would affect, counted before it is taken.
     *
     * A reviewer approving an ownership claim on a brand four other shops are already selling under
     * should know that before they click, not after.
     */
    public function listingsUnder(int|string $brandId, int|string|null $exceptSellerId = null): int
    {
        if (!Schema::hasTable('products')) {
            return 0;
        }

        return Product::withoutGlobalScope('translate')
            ->where('brand_id', $brandId)
            ->where('added_by', 'seller')
            ->when($exceptSellerId !== null, fn ($query) => $query->where('user_id', '!=', $exceptSellerId))
            ->count();
    }

    /** @return array<int, int> the other sellers already approved for this brand */
    private function otherApprovedClaims(BrandClaim $claim): array
    {
        return BrandClaim::where('brand_id', $claim->brand_id)
            ->where('id', '!=', $claim->id)
            ->where('status', BrandClaim::STATUS_APPROVED)
            ->pluck('seller_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
