<?php

namespace App\Services\Accounting\Odoo;

/**
 * What was LEARNED about one customer's Odoo, as opposed to what anybody assumed about it.
 *
 * Every field here is read from the instance or left null. There is no default version, no default transport and no assumption
 * that the external API is even available — because it is not always: Odoo's own pages say the external API is "only available on
 * _Custom_ Odoo pricing plans", which is a fact about the customer's subscription that no code here can change and that this
 * object has to be able to express.
 *
 * `reachable` and `authenticated` are deliberately separate. A server that answers `version()` but refuses the credentials is a
 * different problem from one that cannot be reached at all, and a person fixes them in different places.
 */
final readonly class OdooInstance
{
    /**
     * @param array<string, mixed> $versionInfo exactly what `version()` returned, kept whole because its keys are not all documented
     * @param list<string> $problems stable codes a person can act on, never the server's raw text
     */
    public function __construct(
        public bool $reachable,
        public bool $authenticated,
        public OdooTransport $transport,
        public ?string $serverVersion = null,
        public ?int $majorVersion = null,
        public ?int $userId = null,
        public ?string $database = null,
        public array $versionInfo = [],
        public array $problems = [],
    ) {
    }

    public static function unreachable(string $problem): self
    {
        return new self(false, false, OdooTransport::Unknown, problems: [$problem]);
    }

    /** May anything at all be sent? Reachable, signed in, and on a transport we actually know how to speak. */
    public function usable(): bool
    {
        return $this->reachable && $this->authenticated && $this->transport !== OdooTransport::Unknown;
    }

    /**
     * The major version — and THE TWO ENDPOINTS SPELL THEIR KEYS DIFFERENTLY, which is why both spellings are read here.
     *
     *  - XML-RPC `common.version()` answers `server_version` ("18.0") and `server_version_info` ([18, 0, 0, "final", 0]).
     *  - JSON-2 replaces that call with `GET /web/version`, which answers `version` ("19.0") and `version_info`
     *    ([19, 0, 0, "final", 0, ""]) — **no `server_` prefix**.
     *
     * Reading only the `server_`-prefixed keys would have made every 19.0 instance look like one whose version could not be read,
     * and an unreadable version picks no transport at all. So both are accepted, the structured list first because it needs no
     * parsing.
     *
     * The string forms are read too, because a SaaS instance labels itself `saas~17.4+e` where a self-hosted one says `18.0`: the
     * major number is the first run of digits in either, and a `saas~` prefix must not be mistaken for a missing version.
     *
     * @param array<string, mixed> $versionInfo
     */
    public static function majorFrom(array $versionInfo): ?int
    {
        foreach (['server_version_info', 'version_info'] as $key) {
            $info = $versionInfo[$key] ?? null;
            if (is_array($info) && isset($info[0]) && is_numeric($info[0])) {
                return (int) $info[0];
            }
        }

        foreach (['server_version', 'version'] as $key) {
            $text = trim((string) ($versionInfo[$key] ?? ''));
            if ($text !== '' && preg_match('/(\d+)/', $text, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        // Unreadable, which is NOT the same as old: `OdooTransport::forMajorVersion(null)` chooses nothing rather than guessing
        // at XML-RPC, because a wrong transport fails in a way nobody can read.
        return null;
    }
}
