<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminToken;
use Illuminate\Http\Request;
use LogicException;

/**
 * Who is calling the platform admin API: an admin, the grant that lets them in, the session they hold and the markets they may see.
 *
 * It is not a seller and it is built from none of the seller credentials. It lives on the request's attribute bag, where the framework keeps
 * per-request objects and where a caller cannot put one, never in its input.
 */
final class PlatformAdminPrincipal
{
    public const REQUEST_ATTRIBUTE = 'platform_admin_principal';

    /** @param list<string> $markets the codes of the markets this admin may see, in the order of the configuration */
    public function __construct(
        public readonly Admin $admin,
        public readonly PlatformAdminGrant $grant,
        public readonly PlatformAdminToken $token,
        public readonly array $markets,
    ) {
    }

    /** The principal the authentication middleware put on the request. A route that reaches a controller without one is a mistake, and it is refused rather than served. */
    public static function fromRequest(Request $request): self
    {
        $principal = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (!$principal instanceof self) {
            throw new LogicException('A platform admin route was reached without the authentication middleware.');
        }

        return $principal;
    }

    public function adminId(): int
    {
        return (int) $this->admin->getKey();
    }

    public function level(): int
    {
        return $this->grant->level;
    }

    /** The platform owner sees every market; anyone else holds a list of them. */
    public function isPlatformOwner(): bool
    {
        return $this->grant->countryList() === null;
    }

    public function scope(): string
    {
        return $this->isPlatformOwner() ? 'all' : 'restricted';
    }

    public function canSee(string $market): bool
    {
        return in_array($market, $this->markets, true);
    }
}
