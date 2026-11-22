<?php

namespace App\Enums\PlatformAdmin;

/** How a platform admin passed the second factor: with the code of an authenticator app, or with one of the one-time recovery codes. */
enum MfaMethod: string
{
    case Totp = 'totp';
    case RecoveryCode = 'recovery_code';
}
