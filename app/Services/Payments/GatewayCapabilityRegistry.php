<?php

namespace App\Services\Payments;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

/**
 * Whether a provider's support for one market, in one currency, by one method, has actually been CONFIRMED (D2-05).
 *
 * The platform's foundation rule is that a country named in documentation counts for nothing. A gateway's own
 * currency list says what the GATEWAY can do in general; it says nothing about whether this merchant's account with
 * that provider is live in Kuwait, in dinars, for cards. That is a fact somebody has to obtain from the provider
 * and write down, with a date against it, and it is the owner's open question Q-33 for every cell.
 *
 * So an entry is not a configuration convenience, it is a RECORD, and it has to carry the four things that make it
 * one:
 *
 *  - `confirmed` **strictly true**. Not 1, not "yes", not "true" -- a value that merely looks affirmative is a
 *    value somebody typed without checking, and this refuses it and says so. The one way to confirm a market is to
 *    write the boolean;
 *  - `provider`, a non-empty name: WHO confirmed it;
 *  - `confirmed_on`, a date that can be read and is not in the future: WHEN. A cell confirmed years ago is still a
 *    record and is still honoured -- going stale is a question for a person, not a reason for this to start
 *    refusing payments on its own -- but it is reported with its date so a person can ask;
 *  - `currencies`, a non-empty list: a market confirmed "in general" is not confirmed. A currency not in the list
 *    is not granted even for a confirmed market.
 *
 * `methods` is optional and absent means every method, which is said out loud rather than left to be inferred: a
 * provider confirmation that does not break down by method is the ordinary case, and demanding one would make every
 * cell unconfirmable.
 *
 * **IT REFUSES NOTHING UNTIL IT IS SWITCHED ON.** `gcc.payments.capabilities.mode` ships `off`, where
 * {@see confirms()} answers true for everything and the registry decides nothing -- because the alternative, a
 * default-deny registry switched on with an empty table, is a platform that cannot take a payment anywhere. Default
 * deny is what `enforce` means, and it is a deliberate act with the cells written down first.
 */
#[Singleton]
final class GatewayCapabilityRegistry
{
    /** Nothing is refused and nothing is granted: the registry is not consulted. */
    public const OFF = 'off';

    /** Default deny: a cell with no confirmed entry grants nothing. */
    public const ENFORCE = 'enforce';

    public const NOT_LISTED = 'no_confirmed_entry_for_this_gateway_in_this_market';

    public const NOT_CONFIRMED = 'the_entry_does_not_carry_confirmed_true';

    public const NO_PROVIDER = 'the_entry_does_not_name_who_confirmed_it';

    public const NO_DATE = 'the_entry_does_not_carry_a_readable_confirmation_date';

    public const DATE_IN_THE_FUTURE = 'the_entry_is_confirmed_on_a_date_that_has_not_happened';

    public const NO_CURRENCY = 'the_entry_names_no_currency';

    public const CURRENCY_NOT_CONFIRMED = 'this_currency_is_not_one_the_entry_confirms';

    public const METHOD_NOT_CONFIRMED = 'this_method_is_not_one_the_entry_confirms';

    public function mode(): string
    {
        return (string) config('gcc.payments.capabilities.mode') === self::ENFORCE ? self::ENFORCE : self::OFF;
    }

    public function isEnforced(): bool
    {
        return $this->mode() === self::ENFORCE;
    }

    /** Whether this cell is confirmed. True for everything while the registry is off. */
    public function confirms(string $gateway, string $country, ?string $currency = null, ?string $method = null): bool
    {
        return !$this->isEnforced() || $this->refusalFor(gateway: $gateway, country: $country, currency: $currency, method: $method) === null;
    }

    /**
     * Why this cell is not confirmed, or null when it is. Answered whatever the mode, so a readiness report can say
     * what an operator would have to obtain BEFORE the registry is switched on.
     */
    public function refusalFor(string $gateway, string $country, ?string $currency = null, ?string $method = null): ?string
    {
        $entry = $this->entryFor(gateway: $gateway, country: $country);

        if ($entry === null) {
            return self::NOT_LISTED;
        }

        // Strictly true. `1`, `'1'`, `'yes'` and `'true'` are all refused: a provider confirmation is written down
        // deliberately or not at all, and a loose comparison here would let a default-deny registry be opened by a
        // value somebody pasted.
        if (($entry['confirmed'] ?? null) !== true) {
            return self::NOT_CONFIRMED;
        }

        if (trim((string) ($entry['provider'] ?? '')) === '') {
            return self::NO_PROVIDER;
        }

        $confirmedOn = $this->dateIn(entry: $entry);

        if ($confirmedOn === null) {
            return self::NO_DATE;
        }

        if ($confirmedOn->isAfter(Carbon::now())) {
            return self::DATE_IN_THE_FUTURE;
        }

        $currencies = $this->listIn(entry: $entry, key: 'currencies');

        if ($currencies === []) {
            return self::NO_CURRENCY;
        }

        if ($currency !== null && !in_array(strtoupper(trim($currency)), $currencies, true)) {
            return self::CURRENCY_NOT_CONFIRMED;
        }

        $methods = $this->listIn(entry: $entry, key: 'methods');

        if ($method !== null && $methods !== [] && !in_array(strtolower(trim($method)), array_map('strtolower', $methods), true)) {
            return self::METHOD_NOT_CONFIRMED;
        }

        return null;
    }

    /**
     * Every gateway confirmed for a market, in a currency and by a method when those are given.
     *
     * While the registry is off this answers the list it was given, unchanged: the registry decides nothing then,
     * and a caller that filtered on it would be enforcing a switch that is off.
     *
     * @param list<string> $gateways
     *
     * @return list<string>
     */
    public function confirmedOf(array $gateways, string $country, ?string $currency = null, ?string $method = null): array
    {
        if (!$this->isEnforced()) {
            return array_values($gateways);
        }

        return array_values(array_filter(
            $gateways,
            fn (string $gateway): bool => $this->confirms(gateway: $gateway, country: $country, currency: $currency, method: $method),
        ));
    }

    /**
     * The whole register, flattened, with each cell's refusal beside it: what a readiness report prints and what
     * tells an operator which cells are still a question for the provider.
     *
     * @return list<array{gateway: string, country: string, provider: ?string, confirmed_on: ?string, currencies: list<string>, methods: list<string>, refusal: ?string}>
     */
    public function entries(): array
    {
        $rows = [];

        foreach ((array) config('gcc.payments.capabilities.gateways', []) as $gateway => $countries) {
            foreach ((array) $countries as $country => $entry) {
                $entry = is_array($entry) ? $entry : [];
                $date = $this->dateIn(entry: $entry);

                $rows[] = [
                    'gateway' => (string) $gateway,
                    'country' => strtoupper((string) $country),
                    'provider' => ($entry['provider'] ?? null) === null ? null : (string) $entry['provider'],
                    'confirmed_on' => $date?->toDateString(),
                    'currencies' => $this->listIn(entry: $entry, key: 'currencies'),
                    'methods' => $this->listIn(entry: $entry, key: 'methods'),
                    'refusal' => $this->refusalFor(gateway: (string) $gateway, country: (string) $country),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['gateway'], $a['country']] <=> [$b['gateway'], $b['country']]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function entryFor(string $gateway, string $country): ?array
    {
        $entry = config('gcc.payments.capabilities.gateways.' . $gateway . '.' . strtoupper(trim($country)));

        return is_array($entry) ? $entry : null;
    }

    /** @param array<string, mixed> $entry */
    private function dateIn(array $entry): ?Carbon
    {
        $written = $entry['confirmed_on'] ?? null;

        if (!is_string($written) || trim($written) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($written));
        } catch (\Throwable) {
            // An unreadable date is no date. It is not an exception out of a payment path.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string> upper-cased, blanks dropped
     */
    private function listIn(array $entry, string $key): array
    {
        $values = $entry[$key] ?? null;

        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => strtoupper(trim((string) $value)), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
