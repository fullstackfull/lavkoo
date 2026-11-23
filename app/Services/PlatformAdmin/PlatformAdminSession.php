<?php

namespace App\Services\PlatformAdmin;

/** A session that was just opened: who it is for, and the plain token, which exists here and in the response of the sign-in and nowhere else. */
final readonly class PlatformAdminSession
{
    public function __construct(public PlatformAdminPrincipal $principal, public string $plainToken)
    {
    }
}
