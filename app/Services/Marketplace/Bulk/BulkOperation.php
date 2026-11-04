<?php

namespace App\Services\Marketplace\Bulk;

use App\Models\Product;
use App\Services\Marketplace\SellerPrincipal;

/**
 * One kind of bulk change.
 *
 * The runner owns everything that is the same for every bulk operation — ownership scoping, the
 * receipt, the counts, the failure list, the audit line — so an operation only has to answer two
 * questions: is this request coherent, and what does it do to one product.
 *
 * An operation never decides whether the seller owns the product and never writes to the receipt.
 * Keeping that out of the operations is what stops the next one from forgetting it.
 */
interface BulkOperation
{
    /** Stable identifier stored on the job row, e.g. `price_update`. */
    public function type(): string;

    /** The permission a staff member needs. An owner is not permission-checked. */
    public function permission(): string;

    /**
     * Validation rules for the operation's own settings — not the product list, which the runner
     * validates identically for everyone.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Apply the change to one product.
     *
     * Returning `ok: false` is an ordinary outcome, not an exception: it means this row was refused
     * for a reason worth telling the seller, and the rest of the job carries on. The reason is a
     * translation key so the app can say it in the seller's language.
     *
     * @param  array<string, mixed>  $settings  the validated operation settings
     * @return array{ok: bool, reason?: string, before?: array<string, mixed>, after?: array<string, mixed>}
     */
    public function apply(Product $product, array $settings, SellerPrincipal $principal): array;
}
