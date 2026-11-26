<?php

namespace App\Services\Identity;

use App\Services\Fulfillment\Support\MarketPhone;
use App\Services\Fulfillment\Support\SaudiPhone;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Support\Facades\DB;

/**
 * One customer's phone number as an identity: which market it belongs to, and whether an account already holds it (A2-06).
 *
 * Two different things were missing, and they are two criteria of that card.
 *
 * **A number with no country code was read as Saudi** -- `SaudiPhone::normalize()` prefixes 966 and judges it by the Kingdom's pattern.
 * For a platform serving one country that is not a guess, it is the fact; for six it is the wrong answer five times out of six, and it
 * would reject a Kuwaiti customer's own eight-digit number at the one place that decides whether they can have an account at all. A
 * number that names no country belongs to the market the REQUEST is in, and to the DEFAULT storefront where a request names none -- the
 * same rule every market-owning row of this platform is read by ({@see StorefrontMarketRows}), so a Saudi installation answers Saudi
 * Arabia exactly as it always has.
 *
 * **A second account for the same number was refused only by an exact string.** `unique:users` compares what was typed, so `05XX XXX XXX`,
 * `+9665XX XXX XXX` and `00966 5X XXX XXX` were three different customers with one phone, and whichever row a later sign-in found first
 * decided which account the person got. The canonical key is the one spelling of a number -- `+` then the market's dialling code then the
 * national digits with no trunk zero -- and {@see accountHoldingTheSameNumber()} looks for an account by it.
 *
 * **No schema change, and therefore one known limit.** Whether `users` gets a canonical column, a country code or a unique key is the
 * owner's open decision, because it is a migration over live data that has to be counted first. So the search is made from the SPELLINGS
 * the number could have been stored in -- an exact `whereIn`, which uses the index `users.phone` has -- and every candidate is confirmed
 * with `SaudiPhone::isSameNumber()`, which compares by digits across any country code and one trunk zero: the helper PR 62 already proved
 * on eighteen codes and five thousand seeded pairs.
 *
 * The limit is a row whose number was stored WITH SEPARATORS in it (`00966 5X XXX XXX`): it is not one of the spellings, so it is not
 * found, and a second account for that number is still possible. A suffix search would find it only by scanning the table and then
 * truncating the candidates, which on a live table is both slow and wrong -- the real duplicate may not be in the first page of rows
 * matching the last digits. The remedy is the canonical column, which is the owner's decision; until then this limit is pinned by a test
 * rather than papered over, because a guard that half works has to say which half.
 */
class CustomerPhone
{
    /** How many accounts are confirmed in PHP: a list of exact spellings of one number cannot honestly match more than a handful. */
    private const CANDIDATE_LIMIT = 50;

    /** Off as it ships: a creation path refuses a second account for a number exactly as it does today, by the string that was typed. */
    public static function isChecked(): bool
    {
        return (bool) config('gcc.identity.canonical_phone.checked');
    }

    /**
     * The market a number belongs to: the one its own country code names, and the market being served when it names none.
     *
     * A number that carries a country code no market of ours has belongs to no market of ours, and null says exactly that rather than
     * inventing one. A market is only recognised by its code when the rest of the digits are a number of that market, so the digits of a
     * Saudi number are not read as a Kuwaiti one that happens to start the same way.
     */
    public static function marketOf(?string $raw, ?string $servedMarket = null): ?string
    {
        if (trim((string) $raw) === '') {
            return null;
        }

        foreach (self::marketsByLongestDiallingCode() as $market => $diallingCode) {
            if (self::namesItsOwnCountryCode($raw, $diallingCode) && MarketPhone::nationalNumberFor($market, $raw) !== null) {
                return $market;
            }
        }

        return self::marketBeingServed($servedMarket);
    }

    /**
     * The one spelling of this number: `+` the market's dialling code and the national digits without a trunk zero.
     *
     * Null when no market can judge it -- a number of a country the platform has no numbering plan for, or one that is not a mobile
     * number of its own market. A caller that gets null falls back to what it did before, because a key nobody can compute must not
     * become a refusal.
     */
    public static function canonical(?string $raw, ?string $servedMarket = null): ?string
    {
        $market = self::marketOf($raw, $servedMarket);
        $national = MarketPhone::nationalNumberFor($market, $raw);
        $diallingCode = MarketPhone::diallingCodeOf($market);

        if ($national === null || $diallingCode === null) {
            return null;
        }

        return '+' . $diallingCode . ltrim($national, '0');
    }

    /**
     * The id of an account that already holds this number in any spelling, or null when none does.
     *
     * `$exceptId` is the account being edited, which holds its own number and is not a duplicate of itself.
     */
    public function accountHoldingTheSameNumber(?string $raw, ?string $servedMarket = null, int|string|null $exceptId = null): ?int
    {
        $canonical = self::canonical($raw, $servedMarket);

        if ($canonical === null) {
            return null;
        }

        foreach (self::accountsWithPhoneIn(self::spellingsOf($raw, $servedMarket), $exceptId) as $account) {
            if (SaudiPhone::isSameNumber((string) $account->phone, (string) $raw)) {
                return (int) $account->id;
            }
        }

        return null;
    }

    /**
     * Every spelling of this number a row could have been written in: the national form with and without a trunk zero, and the
     * international form with `+`, `00`, the bare code, and each of those followed by a trunk zero.
     *
     * @return list<string>
     */
    public static function spellingsOf(?string $raw, ?string $servedMarket = null): array
    {
        $market = self::marketOf($raw, $servedMarket);
        $national = MarketPhone::nationalNumberFor($market, $raw);
        $diallingCode = MarketPhone::diallingCodeOf($market);

        if ($national === null || $diallingCode === null) {
            return [];
        }

        $bare = ltrim($national, '0');
        $spellings = [$bare, '0' . $bare];

        foreach (['+' . $diallingCode, '00' . $diallingCode, $diallingCode] as $prefix) {
            $spellings[] = $prefix . $bare;
            $spellings[] = $prefix . '0' . $bare;
        }

        return array_values(array_unique($spellings));
    }

    /**
     * @param list<string> $spellings
     * @return list<object{id: int|string, phone: ?string}>
     */
    private static function accountsWithPhoneIn(array $spellings, int|string|null $exceptId): array
    {
        if ($spellings === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('phone', $spellings)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'phone'])
            ->all();
    }

    /** Whether the raw number itself names this dialling code, so a market is only tried on a number that says it belongs to it. */
    private static function namesItsOwnCountryCode(string $raw, string $diallingCode): bool
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

        return str_starts_with($digits, $diallingCode);
    }

    /**
     * The markets of the configured phone table, longest dialling code first, so a longer code is never shadowed by a shorter one it
     * begins with.
     *
     * @return array<string, string>
     */
    private static function marketsByLongestDiallingCode(): array
    {
        $codes = [];
        foreach (array_keys((array) config('gcc.storefronts.phone', [])) as $market) {
            $code = MarketPhone::diallingCodeOf((string) $market);

            if ($code !== null) {
                $codes[(string) $market] = $code;
            }
        }
        uasort($codes, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $codes;
    }

    /** The market a number with no country code is attached to: the one being served, or the default storefront. */
    private static function marketBeingServed(?string $servedMarket): string
    {
        $named = strtoupper(trim((string) ($servedMarket ?? StorefrontCatalog::country())));

        return $named === '' ? app(StorefrontMarketRows::class)->defaultCountry() : $named;
    }
}
