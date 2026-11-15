<?php

namespace App\Services\Storefront;

use App\Models\StorefrontLegalDocument;

/**
 * Whether a storefront may sell (GCC-11). Each required document type needs a published row for the country whose text was approved by
 * someone other than its author, is not empty, and is not an unedited template. The default storefront is grandfathered (GCC-19): its
 * documents are the ones it has always had, so it never becomes unusable. Nothing enforces this yet; callers ask it.
 */
final class StorefrontLegalReadiness
{
    public function isReady(string $countryCode): bool
    {
        $country = strtoupper(trim($countryCode));

        if (in_array($country, (array) config('gcc.storefronts.legal.grandfathered', []), true)) {
            return true;
        }

        $required = (array) config('gcc.storefronts.legal.required_documents', []);
        $published = StorefrontLegalDocument::query()
            ->where('country_code', $country)
            ->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)
            ->whereNotNull('approved_at')
            ->whereColumn('approved_by', '!=', 'created_by')
            ->whereRaw("trim(body) <> ''")
            ->where(fn ($query) => $query->whereNull('template_hash')->orWhereColumn('body_hash', '!=', 'template_hash'))
            ->distinct()
            ->pluck('document_type')
            ->all();

        return array_diff($required, $published) === [];
    }
}
