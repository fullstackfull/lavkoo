<?php

namespace App\Services\PlatformAdmin;

use App\Services\Storefront\StorefrontLegalReadiness;

/**
 * The markets an admin may see, each with what the configuration and the storefront's own legal gate say about it. Readiness is asked of
 * StorefrontLegalReadiness, the code that decides whether a storefront may sell, and is not worked out a second time here.
 *
 * The company that sells in a market is the one `legal_entities` records for that country (PlatformLegalEntities), and never the platform-wide
 * `gcc.storefronts.legal.legal_entity`: one value repeated under six markets would say that one company sells in all of them, which is the
 * opposite of the approved plan that each country has a selling company of its own. A market with no company recorded says so.
 */
final class PlatformMarketDirectory
{
    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly PlatformLegalEntities $entities,
    ) {
    }

    /**
     * @return list<array{code: string, path: ?string, currency: ?string, active: bool, storefront_enabled: bool, legal_ready: bool, legal_entity: ?string, legal_entity_id: ?int, legal_entity_trade_name: ?string}>
     */
    public function listFor(PlatformAdminPrincipal $principal): array
    {
        $storefrontsEnabled = $this->markets->storefrontsEnabled();
        $listed = [];

        foreach ($this->markets->all() as $code => $market) {
            if ($principal->canSee(market: $code)) {
                $entity = $this->entities->of(market: $code);

                $listed[] = [
                    'code' => $code,
                    'path' => $market['path'],
                    'currency' => $market['currency'],
                    'active' => $market['active'],
                    'storefront_enabled' => $storefrontsEnabled,
                    'legal_ready' => $this->legalReadiness->isReady($code),
                    'legal_entity' => $entity['name'] ?? null,
                    'legal_entity_id' => $entity['id'] ?? null,
                    'legal_entity_trade_name' => $entity['trade_name'] ?? null,
                ];
            }
        }

        return $listed;
    }
}
