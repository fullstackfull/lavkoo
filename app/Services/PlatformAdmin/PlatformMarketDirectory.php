<?php

namespace App\Services\PlatformAdmin;

use App\Services\Storefront\StorefrontLegalReadiness;

/**
 * The markets an admin may see, each with what the configuration and the storefront's own legal gate say about it. Readiness is asked of
 * StorefrontLegalReadiness, the code that decides whether a storefront may sell, and is not worked out a second time here.
 */
final class PlatformMarketDirectory
{
    public function __construct(private readonly PlatformMarkets $markets, private readonly StorefrontLegalReadiness $legalReadiness)
    {
    }

    /**
     * @return list<array{code: string, path: ?string, currency: ?string, active: bool, storefront_enabled: bool, legal_ready: bool, legal_entity: ?string}>
     */
    public function listFor(PlatformAdminPrincipal $principal): array
    {
        $storefrontsEnabled = $this->markets->storefrontsEnabled();
        $legalEntity = $this->markets->legalEntity();
        $listed = [];

        foreach ($this->markets->all() as $code => $market) {
            if ($principal->canSee(market: $code)) {
                $listed[] = [
                    'code' => $code,
                    'path' => $market['path'],
                    'currency' => $market['currency'],
                    'active' => $market['active'],
                    'storefront_enabled' => $storefrontsEnabled,
                    'legal_ready' => $this->legalReadiness->isReady($code),
                    'legal_entity' => $legalEntity,
                ];
            }
        }

        return $listed;
    }
}
