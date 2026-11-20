<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Collection;

/**
 * The currency a settlement is drawn in, and how an amount of a settlement is printed (E-17.1).
 *
 * Everything that has to tell one currency of the seller ledger from another asks here, so the engine that draws a settlement, the command that reports one and the screens
 * that print one cannot disagree about which currency an entry is in. SettlementEngine explains why an entry that names no currency is read as the store's base currency. The
 * code itself is spelled by `VendorLedger::currencyCode()`, so a settlement cannot normalise a label differently from the ledger chain the entry's money runs in.
 *
 * ## Two switches, one answer
 *
 * `gcc.settlements.one_currency` asks for currencies to be told apart directly. `gcc.ledgers.keyed_by_entity_and_currency` (E-17.2) forces it: a running balance then belongs
 * to one currency, so a settlement drawn across two of them would report a balance belonging to only one. `areToldApart()` is the one answer to that question, and the engine
 * and the screens both ask it; while both switches are off nothing is told apart, every entry answers the same, empty, code and every amount is printed as the platform's
 * money, which is what the platform did before either switch existed.
 */
class SettlementCurrency
{
    public const NOT_TOLD_APART = '';

    private const NOT_KNOWN = '—';

    private const BETWEEN_CURRENCIES = ' · ';

    private const DECIMALS_OF_THE_LEDGER = 4;

    private const FEWEST_DECIMALS = 2;

    private ?string $baseCurrency = null;

    public function __construct(private readonly VendorLedger $ledger)
    {
    }

    public function isOnePerSettlement(): bool
    {
        return (bool) config('gcc.settlements.one_currency');
    }

    /**
     * Whether one currency of the seller ledger is told from another at all.
     *
     * Either switch is enough. `gcc.settlements.one_currency` asks for it. A ledger kept apart per chain (E-17.2) forces it: `balance_after` then runs per currency, so a
     * settlement drawn across two currencies would record an opening and a closing balance belonging to only one of them, and a screen printing the sum would add money of
     * two kinds together.
     */
    public function areToldApart(): bool
    {
        return $this->isOnePerSettlement() || LedgerChain::areKeptApart();
    }

    /** The code of the currency an entry with this label is settled in: the one it names, or the store's base currency when it names none. Empty when neither is known, or while currencies are not told apart. */
    public function of(?string $label): string
    {
        return $this->areToldApart() ? $this->codeOfTheMoney(label: $label) : self::NOT_TOLD_APART;
    }

    /**
     * The code of the currency an entry's money is in, whatever the switches say: the one it names, or the store's base currency when it names none.
     *
     * `of()` answers the empty code while currencies are not told apart, because a settlement drawn then does not tell them apart. The money itself is always in one currency,
     * which is what the ledger chain is keyed by, so the two are not the same question and are not the same method.
     */
    private function codeOfTheMoney(?string $label): string
    {
        $code = $this->code(label: $label);

        return $code !== '' ? $code : $this->code(label: $this->baseCurrency());
    }

    /**
     * What a settlement of entries with these labels records as its currency: the first one named, as its entry wrote it, or the store's base currency when none is named.
     *
     * @param Collection<int, string|null> $labels
     */
    public function labelOf(Collection $labels): ?string
    {
        return $labels->concat([$this->baseCurrency()])->first(fn (?string $label): bool => $this->code(label: $label) !== '');
    }

    /**
     * An amount of a settlement as a screen prints it. The platform's own currency is printed as the platform prints money everywhere; another currency is printed
     * with its code. A figure the settlement does not record is not printed as a zero.
     */
    public function format(string|int|float|null $amount, ?string $currency): string
    {
        if ($amount === null) {
            return self::NOT_KNOWN;
        }

        if ($this->of(label: $currency) === $this->of(label: null)) {
            return setCurrencySymbol(amount: $amount);
        }

        return $this->amount(amount: $amount) . ' ' . $this->of(label: $currency);
    }

    /**
     * Amounts of several currencies side by side, never added.
     *
     * @param array<string, float> $amounts the amount in each currency, by its code
     */
    public function formatEach(array $amounts): string
    {
        return implode(self::BETWEEN_CURRENCIES, array_map(
            fn (string|int $currency): string => $this->format(amount: $amounts[$currency], currency: (string) $currency),
            array_keys($amounts),
        ));
    }

    /**
     * An amount to the decimals the ledger stores it with, and never fewer than two. The platform's decimal setting says how many decimals its own currency has, not
     * how many another one has, so an amount in another currency is not rounded to it.
     */
    public function amount(string|int|float $amount): string
    {
        $stored = number_format((float) $amount, self::DECIMALS_OF_THE_LEDGER, '.', '');
        $decimalsInUse = strlen(rtrim(substr($stored, strpos($stored, '.') + 1), '0'));

        return number_format((float) $amount, max(self::FEWEST_DECIMALS, $decimalsInUse));
    }

    private function code(?string $label): string
    {
        return $this->ledger->currencyCode(label: $label);
    }

    private function baseCurrency(): string
    {
        return $this->baseCurrency ??= (string) $this->ledger->baseCurrency();
    }
}
