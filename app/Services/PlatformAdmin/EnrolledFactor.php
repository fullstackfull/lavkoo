<?php

namespace App\Services\PlatformAdmin;

/** What enrolling a second factor gives the operator, once: the secret as an authenticator app is typed it, the address it reads, and the recovery codes. None of it is kept in this form. */
final readonly class EnrolledFactor
{
    /** @param list<string> $recoveryCodes */
    public function __construct(public string $secret, public string $uri, public array $recoveryCodes)
    {
    }
}
