<?php

namespace App\Enums\Address;

/**
 * Where an address is being entered. The website and the staff panels are ours and always use the structured form; the mobile
 * app and other API clients are older programs that may need time to catch up, which `soft` mode allows for.
 */
enum AddressChannel: string
{
    case Web = 'web';
    case Panel = 'panel';
    case Api = 'api';

    /** May this channel still send the old free-text city when the store is in `soft` mode? */
    public function mayStayLegacyInSoftMode(): bool
    {
        return $this === self::Api;
    }
}
