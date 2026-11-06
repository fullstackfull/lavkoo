<?php

namespace App\Enums\SalesChannels;

enum ConnectionStatus: string
{
    case Untested = 'untested';
    case Ok = 'ok';
    case Failed = 'failed';
    /** The channel refused the credentials: the seller must authorise again. */
    case AuthExpired = 'auth_expired';
    case Revoked = 'revoked';

    public function isUsable(): bool
    {
        return $this === self::Ok;
    }

    public function labelKey(): string
    {
        return 'connection_status_' . $this->value;
    }
}
