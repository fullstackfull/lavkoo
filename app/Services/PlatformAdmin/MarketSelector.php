<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminApiException;
use Illuminate\Http\Request;

/**
 * Reads the `market` parameter and holds it to what the admin may see. The market is never taken from anything else: not the storefront
 * header, not a country in the query, not a session. A value that is not a market is a 422 that lists the ones that are, and a market that is
 * not the admin's is a 403, whatever its case.
 */
final class MarketSelector
{
    public function __construct(private readonly PlatformMarkets $markets)
    {
    }

    /**
     * @throws PlatformAdminApiException
     */
    public function select(Request $request, PlatformAdminPrincipal $principal): MarketSelection
    {
        $requested = $this->requestedMarket(given: $request->query('market'));

        if ($requested !== MarketSelection::ALL) {
            $request->attributes->set(PlatformAdminAudit::MARKET_ATTRIBUTE, $requested);

            if (!$principal->canSee(market: $requested)) {
                throw PlatformAdminApiException::marketNotAllowed();
            }
        }

        $asksForAll = $requested === MarketSelection::ALL;

        return new MarketSelection(
            requested: $requested,
            markets: $asksForAll ? $principal->markets : [$requested],
            scope: $principal->scope(),
            includesUnattributed: $asksForAll && $principal->isPlatformOwner(),
        );
    }

    private function requestedMarket(mixed $given): string
    {
        if ($given === null) {
            return MarketSelection::ALL;
        }

        if (is_string($given) && strtolower(trim($given)) === MarketSelection::ALL) {
            return MarketSelection::ALL;
        }

        return $this->markets->normalise(value: $given) ?? throw PlatformAdminApiException::invalidMarket(valid: [MarketSelection::ALL, ...$this->markets->codes()]);
    }
}
