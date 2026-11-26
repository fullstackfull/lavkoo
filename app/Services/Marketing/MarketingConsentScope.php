<?php

namespace App\Services\Marketing;

use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;

/**
 * Which storefront a marketing decision was given on, and which company it was given to (A2-13, M-556).
 *
 * The row has always carried the MARKET — the market the decision governs, which is what every sender reads
 * ({@see MarketingMarket}) — and nothing else. `storefront` and `legal_entity_id` existed, were nullable, and were
 * filled by nobody: `MarketingConsentRecorder` took both as parameters and no caller passed either, so every consent
 * row on every head carried two nulls and the market was the only scope that worked.
 *
 * ANSWERED HERE RATHER THAN AT EACH CALL SITE. Three writers reach the recorder today and more will; a scope threaded
 * through arguments is a scope some future caller forgets, and a consent row that cannot name the company it was given
 * to is the one record a regulator would ask for.
 *
 * THE COMPANY IS THE STOREFRONT'S, NOT THE MARKET'S. A row reading "given on the Saudi storefront, to the Kuwaiti
 * company" is not a receipt: what the person was shown is the storefront's text, under the company that sells there.
 * Where nothing names a storefront — the admin panel, a console command, a queued job — the market the decision
 * governs answers instead, which is the shape {@see \App\Services\AuditLogger::marketOf()} already records its own
 * market and company in. The alternative reading (always the market's company, because the market's sender is who
 * acts on the consent) is written down in this track's handoff rather than chosen silently; it differs only for a
 * person whose address names a market other than the storefront they answered on.
 *
 * `forCountry()` is the ONE reader of "who sells in this country" — the order's own stamp
 * ({@see \App\Services\Storefront\OrderLegalEntitySnapshot}), the legal-acceptance receipt and the gateway-account
 * pick all go through it — so a customer's consent cannot name a company the sale does not.
 *
 * DORMANT AS SHIPPED, and that is not a new switch: `StorefrontCatalog::country()` is null while storefronts are off,
 * and the registry answers nothing while legal entities are off (which they are unless storefronts are on too), before
 * its migrations have run, or while a country has no active company. A row then carries the two nulls it carries
 * today, byte for byte, which is what MarketingConsentScopeTest measures first.
 *
 * WHAT THIS IS NOT. Filling the two columns changes no READ: the gate and the recorder key a decision on subject,
 * market, channel and purpose ({@see \App\Models\CustomerMarketingConsent::latestDecision()}), and the market stays
 * the market of the subject's own address. Two storefronts of one market still share a decision; this makes the row
 * say where and under whom it was given, it does not re-scope the answer. Changing the KEY would split the write (a
 * web request, which has a storefront) from the read (a console command and an admin screen, which have none), so the
 * market is deliberately left alone.
 */
final class MarketingConsentScope
{
    /** The storefront the answer was given on, or null where nothing names one: storefronts off, a panel, a seller API, a console command, a queue. */
    public static function storefront(): ?string
    {
        return StorefrontCatalog::country();
    }

    /** The active selling company of this country, or null while the registry answers nothing for it. */
    public static function sellingCompanyId(string $country): ?int
    {
        $entity = app(LegalEntityRegistry::class)->forCountry($country);

        return $entity === null ? null : (int) $entity->id;
    }
}
