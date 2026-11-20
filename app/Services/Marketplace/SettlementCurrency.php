<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Collection;

/**
 * The currency a settlement is drawn in, and how an amount of a settlement is printed (E-17.1).
 *
 * Everything that has to tell one currency of the seller ledger from another asks here, so the engine that draws a settlement, the command that reports one and the screens
 * that print one cannot disagree about which currency an entry is in. SettlementEngine explains why an entry that names no currency is read as the store's base currency.
 *
 * While `gcc.settlements.one_currency` is off nothing is told apart: every entry answers the same, empty, code and every amount is printed as the platform's money, which is
 * what the platform did before the switch existed.
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

    /** The code of the currency an entry with this label is settled in: the one it names, or the store's base currency when it names none. Empty when neither is known. */
    public function of(?string $label): string
    {
        if (!$this->isOnePerSettlement()) {
            return self::NOT_TOLD_APART;
        }

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
        return mb_strtoupper(trim((string) $label));
    }

    private function baseCurrency(): string
    {
        return $this->baseCurrency ??= (string) $this->ledger->baseCurrency();
    }
}
