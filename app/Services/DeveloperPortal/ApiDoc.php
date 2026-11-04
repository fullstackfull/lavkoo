<?php

namespace App\Services\DeveloperPortal;

use Attribute;

/**
 * The only place an endpoint is described by hand — and only for what code cannot say.
 *
 * Everything derivable is derived: the method, the path, the middleware, the auth mechanism, the
 * rate limit, the validation rules and the parameters all come from the live route table and the
 * classes it points at, so they cannot drift. What no amount of reflection can recover is INTENT:
 * what this endpoint is for, whether it is stable, when it was introduced, what replaces it when
 * it is deprecated, and who outside this company is allowed to see it.
 *
 * That is what this attribute carries, and it lives on the controller method itself so it moves
 * with the code, is reviewed in the same diff, and disappears when the method does. A separate
 * documentation file would be a second source of truth with its own decay rate — which is exactly
 * the failure this portal exists to avoid.
 *
 *     #[ApiDoc(
 *         summary: 'Place an order from the current cart',
 *         audience: ApiDoc::CUSTOMER_APP,
 *         visibility: ApiDoc::PARTNER,
 *         since: 'v2.0',
 *         idempotent: true,
 *     )]
 *     public function placeOrder(PlaceOrderRequest $request): JsonResponse
 *
 * An undocumented endpoint is not hidden: it appears in the portal with whatever could be
 * inferred, and is counted against the documentation-quality score so the gap is visible.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiDoc
{
    /** Who calls this endpoint. */
    public const CUSTOMER_APP = 'customer_app';
    public const VENDOR_APP = 'vendor_app';
    public const DELIVERY_APP = 'delivery_app';
    public const WEB = 'web';
    public const ADMIN = 'admin';
    public const PARTNER = 'partner';
    public const PUBLIC_API = 'public';

    /** Who may see it documented. Internal is the safe default for anything unclassified. */
    public const INTERNAL = 'internal';
    public const PARTNER_VISIBLE = 'partner';
    public const PUBLIC_VISIBLE = 'public';

    /** How much a caller may rely on it. */
    public const STABLE = 'stable';
    public const BETA = 'beta';
    public const DEPRECATED = 'deprecated';
    public const EXPERIMENTAL = 'experimental';

    /**
     * @param  string|null  $summary  one line: what this does, in a developer's words
     * @param  string|null  $description  the longer explanation, when one line is not enough
     * @param  string|null  $audience  which client this exists for; inferred from the path when absent
     * @param  string|null  $visibility  internal | partner | public — internal unless stated
     * @param  string  $stability  stable | beta | deprecated | experimental
     * @param  string|null  $since  the version this appeared in
     * @param  string|null  $deprecatedSince  the version it was deprecated in
     * @param  string|null  $sunsetAt  the date it will be removed (ISO), so callers can plan
     * @param  string|null  $replacedBy  the route name or path to use instead
     * @param  array<int, string>  $scopes  permissions or scopes a caller must hold
     * @param  bool  $idempotent  safe to retry with the same Idempotency-Key
     * @param  array<string, mixed>|null  $responseExample  a hand-written example where none can be captured
     * @param  array<int, string>  $emits  webhook or domain events this endpoint can raise
     * @param  array<int, string>  $dependsOn  services this endpoint needs to succeed
     * @param  string|null  $group  overrides the inferred grouping
     * @param  bool  $destructive  deletes or moves money; the console will refuse to fire it blind
     */
    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?string $audience = null,
        public readonly ?string $visibility = null,
        public readonly string $stability = self::STABLE,
        public readonly ?string $since = null,
        public readonly ?string $deprecatedSince = null,
        public readonly ?string $sunsetAt = null,
        public readonly ?string $replacedBy = null,
        public readonly array $scopes = [],
        public readonly bool $idempotent = false,
        public readonly ?array $responseExample = null,
        public readonly array $emits = [],
        public readonly array $dependsOn = [],
        public readonly ?string $group = null,
        public readonly bool $destructive = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'summary' => $this->summary,
            'description' => $this->description,
            'audience' => $this->audience,
            'visibility' => $this->visibility,
            'stability' => $this->stability,
            'since' => $this->since,
            'deprecated_since' => $this->deprecatedSince,
            'sunset_at' => $this->sunsetAt,
            'replaced_by' => $this->replacedBy,
            'scopes' => $this->scopes,
            'idempotent' => $this->idempotent,
            'response_example' => $this->responseExample,
            'emits' => $this->emits,
            'depends_on' => $this->dependsOn,
            'group' => $this->group,
            'destructive' => $this->destructive,
        ], static fn ($value) => $value !== null && $value !== [] && $value !== false);
    }
}
