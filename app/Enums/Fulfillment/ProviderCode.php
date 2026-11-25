<?php

namespace App\Enums\Fulfillment;

use App\Services\Fulfillment\Contracts\ProviderIdentity;

/**
 * The two carriers this platform ships with. A product with no provider (NULL) is "in-house / default":
 * it follows the platform's existing order flow and is never touched by this integration.
 *
 * This enum is no longer the GATE (H1-11). A carrier for another Gulf country is one adapter class plus one entry in
 * `config/fulfillment.php`, with no case here and no change to routing, dispatch, webhooks or the order summary: those
 * read `App\Services\Fulfillment\Contracts\ProviderIdentity`, which this implements, and ask `ProviderRegistry` to
 * resolve a code. The cases stay because the two shipped carriers have behaviour of their own that names them, and
 * because 76 places in the application and 751 in the tests say `ProviderCode::TorodPoint` and mean exactly that.
 */
enum ProviderCode: string implements ProviderIdentity
{
    case M5azn = 'm5azn';
    case TorodPoint = 'torod_point';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }

    /** The stable machine code. The same string as `->value`; named so that code working with any carrier need not know this is an enum. */
    public function code(): string
    {
        return $this->value;
    }

    /** URL form of a provider code: 'torod_point' ⇄ 'torod-point'. */
    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(str_replace('-', '_', $slug));
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * Whether this value is one of the two carriers with a case here.
     *
     * NOT the question "does this platform know this carrier": a carrier declared only in the configuration is known to
     * `ProviderRegistry::isKnown()` and not to this. Call this one only where the two shipped carriers are the subject.
     */
    public static function isKnown(?string $value): bool
    {
        return $value !== null && self::tryFrom($value) !== null;
    }

    /** Short, stable code used in fulfillment references (order 100234 → "100234-MZ1"). */
    public function referenceCode(): string
    {
        return match ($this) {
            self::M5azn => 'MZ',
            self::TorodPoint => 'TP',
        };
    }

    public function label(): string
    {
        return (string) config('fulfillment.providers.' . $this->value . '.label', $this->value);
    }
}
