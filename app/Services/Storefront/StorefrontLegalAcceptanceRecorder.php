<?php

namespace App\Services\Storefront;

use App\Models\StorefrontLegalAcceptance;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Records a customer's acceptance of the text a storefront shows now (GCC-11). Only the document types the configuration marks as
 * needing explicit acceptance are recorded, and identity is kept only when the privacy policy allows it. A guest is kept as a hash.
 */
final class StorefrontLegalAcceptanceRecorder
{
    public function __construct(private readonly StorefrontLegalReadiness $legalReadiness)
    {
    }

    public function recordIfRequired(
        string $storefrontCountry,
        string $documentType,
        string $locale,
        ?int $customerId = null,
        ?string $guestId = null,
        ?int $orderId = null,
        ?CarbonImmutable $acceptedAt = null,
    ): ?StorefrontLegalAcceptance {
        if (!in_array($documentType, (array) config('gcc.storefronts.legal.explicit_acceptance', []), true)) {
            return null;
        }

        $text = $this->legalReadiness->currentTextFor($storefrontCountry, $documentType, $locale);
        if ($text === null) {
            throw new RuntimeException("No effective [{$documentType}] text in [{$locale}] for storefront [{$storefrontCountry}].");
        }

        $storeIdentity = (bool) config('gcc.storefronts.legal.store_identity', false);

        return StorefrontLegalAcceptance::create([
            'storefront_country' => strtoupper($storefrontCountry),
            'legal_entity' => config('gcc.storefronts.legal.legal_entity'),
            'document_type' => $documentType,
            'locale' => strtolower($locale),
            'legal_document_id' => $text->id,
            'document_version' => $text->version,
            'document_hash' => $text->body_hash,
            'customer_id' => $storeIdentity ? $customerId : null,
            'guest_hash' => $storeIdentity && $guestId !== null ? hash('sha256', $guestId) : null,
            'order_id' => $orderId,
            'accepted_at' => $acceptedAt ?? CarbonImmutable::now(),
        ]);
    }
}
