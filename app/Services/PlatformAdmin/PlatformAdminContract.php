<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Http\Request;

/**
 * The version of this API that a shell and the server agree on, and the oldest one the server will still answer.
 *
 * The seller app has a force-update gate in front of its login screen, read from a value the platform publishes
 * (`seller_app_version_control`). That gate is about the SELLER app's release, and the platform admin shell is a different thing inside the same
 * binary: it can stop being able to read this API without the seller app needing a release at all, and a release of the seller app can happen
 * without the shell changing. So the shell has a floor of its own, expressed as a CONTRACT level rather than an app version: what matters is not
 * which build is installed but whether it understands the answers.
 *
 * A request says which level it speaks in `X-Platform-Admin-Contract`. A build from before the header says nothing, which is level 0 and not
 * "whatever is current": an older shell must not be given answers it was never written to read.
 *
 * **The minimum ships unset, and it can never be set above the level the server itself speaks.** Both halves are deliberate:
 *
 *  - unset means nothing is forced, so deploying this changes no request's outcome until somebody sets a floor;
 *  - the ceiling is the lesson of the seller version gate, where a minimum typed one digit too high locked every seller out before the login
 *    screen with no way back but another number. Here a minimum of 2 against a server that speaks 1 would refuse EVERY request, the sign-in
 *    included, so there would be no session left to read the floor from. `minSupported()` therefore holds the configured value to
 *    [`VERSION`](self::VERSION), and a value that is not a positive whole number means no floor at all rather than zero.
 */
final class PlatformAdminContract
{
    /** The level this server speaks. It goes up when an answer of this API changes shape in a way an older shell cannot read. */
    public const VERSION = 1;

    public const HEADER = 'X-Platform-Admin-Contract';

    /** The level a request claims: the header read as a whole number, and 0 for a build that sends none or sends something that is not one. */
    public function claimedBy(Request $request): int
    {
        $given = $request->header(self::HEADER);

        return is_string($given) && ctype_digit(trim($given)) ? (int) trim($given) : 0;
    }

    /**
     * The oldest level the server will answer, or null when no floor is set -- which is how it ships.
     *
     * The configured value is read STRICTLY: an integer, or a string of nothing but digits. `1.5` and `1x` are not levels and mean no floor, rather
     * than the 1 a plain `(int)` cast would silently make of them -- for a floor, the unsafe direction of a nonsense value is to switch forcing ON.
     */
    public function minSupported(): ?int
    {
        $configured = config('gcc.platform_admin.min_contract');
        $level = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && ctype_digit(trim($configured)) => (int) trim($configured),
            default => 0,
        };

        return $level < 1 ? null : min($level, self::VERSION);
    }

    /** Whether a request speaks a level the server still answers. With no floor set, every level does, a build from before the header included. */
    public function isSpokenBy(Request $request): bool
    {
        $minimum = $this->minSupported();

        return $minimum === null || $this->claimedBy(request: $request) >= $minimum;
    }

    /**
     * What the server speaks and what it insists on, as `GET /me` reports it, so that a shell which IS new enough can see where the floor stands
     * and say so before it is refused.
     *
     * @return array{version: int, min_supported: ?int}
     */
    public function toArray(): array
    {
        return ['version' => self::VERSION, 'min_supported' => $this->minSupported()];
    }
}
