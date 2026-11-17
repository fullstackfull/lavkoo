<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;

/** What the check of a code decided: it was right and has now been used, or it was refused and why. */
final readonly class MfaVerdict
{
    public const MALFORMED_INPUT = 'malformed_input';

    public const UNUSABLE_FACTOR = 'unusable_factor';

    public const WRONG_CODE = 'wrong_code';

    public const REPLAYED_CODE = 'replayed_code';

    private function __construct(public ?MfaMethod $method, public ?int $recoveryCodesLeft, public ?string $reason)
    {
    }

    public static function accepted(MfaMethod $method, int $recoveryCodesLeft): self
    {
        return new self(method: $method, recoveryCodesLeft: $recoveryCodesLeft, reason: null);
    }

    public static function refused(string $reason): self
    {
        return new self(method: null, recoveryCodesLeft: null, reason: $reason);
    }

    public function isAccepted(): bool
    {
        return $this->reason === null;
    }

    /** Whether the refusal was a guess (a code that was wrong, or that had been used) and so counts against the admin's lockout; a sign-in that could not be completed for another reason is not a guess. */
    public function wasAGuess(): bool
    {
        return in_array($this->reason, [self::WRONG_CODE, self::REPLAYED_CODE], true);
    }
}
