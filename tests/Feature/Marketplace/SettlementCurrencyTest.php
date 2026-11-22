<?php

namespace Tests\Feature\Marketplace;

use App\Services\Marketplace\SettlementCurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * SettlementCurrency, the one place that says which currency an entry of the seller ledger is settled in and how an amount of a settlement is printed (E-17.1).
 *
 * The platform's currency is SAR, printed as the amount to two decimals followed by the symbol "SAR-symbol", which is what setCurrencySymbol() makes of the settings written in setUp. The store's base
 * currency is read from the same two tables VendorLedger::baseCurrency() reads.
 */
class SettlementCurrencyTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorLedgerTables();
        $this->storeSetting(type: 'decimal_point_settings', value: '2');
        $this->storeSetting(type: 'currency_symbol_position', value: 'right');
    }

    private function currency(bool $onePerSettlement): SettlementCurrency
    {
        config(['gcc.settlements.one_currency' => $onePerSettlement]);

        return app(SettlementCurrency::class);
    }

    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse(config('gcc.settlements.one_currency'));
        $this->assertFalse(app(SettlementCurrency::class)->isOnePerSettlement());
    }

    /** @return array<string, array{string|null}> */
    public static function labels(): array
    {
        return ['a currency' => ['KWD'], 'the platform currency' => ['SAR'], 'no currency' => [null], 'an empty label' => ['']];
    }

    #[DataProvider('labels')]
    public function test_with_the_switch_off_no_currency_is_told_from_another_and_every_amount_is_printed_as_the_platform_prints_money(?string $label): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: false);

        $this->assertSame('', $currency->of(label: $label));
        $this->assertSame(setCurrencySymbol(amount: 12.345), $currency->format(amount: 12.345, currency: $label));
        $this->assertSame('12.35SAR-symbol', $currency->format(amount: 12.345, currency: $label));
        $this->assertSame('1,234.50SAR-symbol', $currency->format(amount: 1234.5, currency: $label));
    }

    public function test_with_the_switch_off_the_base_currency_is_not_even_read(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: false);
        DB::enableQueryLog();

        $currency->of(label: null);
        $currency->of(label: 'KWD');

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_with_the_switch_on_an_entry_is_in_the_currency_it_names_however_the_code_is_written(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame(['KWD', 'KWD', 'KWD', 'SAR', 'AED'], array_map(static fn (string $label): string => $currency->of(label: $label), ['KWD', 'kwd', ' Kwd ', 'SAR', 'aed']));
    }

    public function test_with_the_switch_on_an_entry_that_names_no_currency_is_in_the_base_currency_of_the_store(): void
    {
        $this->useDefaultCurrency(code: 'kwd');
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame(['KWD', 'KWD', 'KWD'], [$currency->of(label: null), $currency->of(label: ''), $currency->of(label: '   ')]);
    }

    public function test_with_the_switch_on_and_no_base_currency_to_read_an_entry_that_names_none_is_in_no_named_currency(): void
    {
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame(['', '', 'SAR'], [$currency->of(label: null), $currency->of(label: ''), $currency->of(label: 'SAR')]);
        $this->assertNull($currency->labelOf(labels: collect([null, ''])));
    }

    /**
     * A settlement asks for the currency of every one of its entries, so the store's base currency is read once and held.
     *
     * What is counted is this service's own reads. Printing an amount of the platform's own money hands it to the platform's
     * formatter, which loads the currency into the session itself on its first call; that read is not this service's and is
     * left out of the count by making it before the counting starts.
     */
    public function test_the_base_currency_is_read_once_however_many_entries_ask(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: true);
        $currency->of(label: null);
        $currency->format(amount: 5.0, currency: null);
        DB::enableQueryLog();

        $currency->of(label: null);
        $currency->of(label: '');
        $currency->format(amount: 5.0, currency: null);
        $currency->labelOf(labels: collect([null]));

        $this->assertSame([], array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $query): bool => str_contains($query, 'currencies') && str_contains($query, 'limit 1') && !str_contains($query, 'code'))));
    }

    public function test_a_settlement_records_the_first_currency_its_entries_name_as_it_is_written_or_the_base_currency_when_none_names_one(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame(' sar', $currency->labelOf(labels: collect([null, '', ' sar', 'SAR'])));
        $this->assertSame('KWD', $currency->labelOf(labels: collect(['KWD', 'kwd'])));
        $this->assertSame('SAR', $currency->labelOf(labels: collect([null, ''])));
    }

    /** @return array<string, array{string|int|float|null, string|null, string}> */
    public static function amountsOfASettlement(): array
    {
        return [
            'three decimals of a dinar' => [12.345, 'KWD', '12.345 KWD'],
            'a whole amount keeps two decimals' => [100, 'KWD', '100.00 KWD'],
            'one decimal becomes two' => [1.5, 'KWD', '1.50 KWD'],
            'four decimals are all printed' => [10.1235, 'AED', '10.1235 AED'],
            'thousands are separated' => [1234567.5, 'kwd', '1,234,567.50 KWD'],
            'a zero in another currency' => [0, 'KWD', '0.00 KWD'],
            'a negative net' => [-5.5, 'KWD', '-5.50 KWD'],
            'the text a database returns' => ['12.3450', 'KWD', '12.345 KWD'],
            'the platform currency as the platform prints it' => [10.1235, 'SAR', '10.12SAR-symbol'],
            'the platform currency in other letters' => [1234.5, ' sar ', '1,234.50SAR-symbol'],
            'no currency is the platform currency' => [5, null, '5.00SAR-symbol'],
            'an empty currency is the platform currency' => [5, '', '5.00SAR-symbol'],
            'a figure that is not recorded, in another currency' => [null, 'KWD', '—'],
            'a figure that is not recorded, in the platform currency' => [null, 'SAR', '—'],
            'a figure that is not recorded, in no currency' => [null, null, '—'],
        ];
    }

    #[DataProvider('amountsOfASettlement')]
    public function test_with_the_switch_on_an_amount_is_printed_in_its_own_currency(string|int|float|null $amount, ?string $currency, string $printed): void
    {
        $this->useDefaultCurrency(code: 'SAR');

        $this->assertSame($printed, $this->currency(onePerSettlement: true)->format(amount: $amount, currency: $currency));
    }

    public function test_with_the_switch_off_a_figure_that_is_not_recorded_is_a_dash_too(): void
    {
        $this->useDefaultCurrency(code: 'SAR');

        $this->assertSame('—', $this->currency(onePerSettlement: false)->format(amount: null, currency: 'KWD'));
    }

    public function test_amounts_of_several_currencies_are_printed_side_by_side_and_never_added(): void
    {
        $this->useDefaultCurrency(code: 'SAR');
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame('6.505 KWD · 30.00SAR-symbol', $currency->formatEach(amounts: ['KWD' => 6.505, 'SAR' => 30.0]));
        $this->assertSame('0.00SAR-symbol', $currency->formatEach(amounts: [SettlementCurrency::NOT_TOLD_APART => 0.0]));
        $this->assertSame('35.50SAR-symbol', $this->currency(onePerSettlement: false)->formatEach(amounts: [SettlementCurrency::NOT_TOLD_APART => 35.5]));
    }

    public function test_an_amount_is_written_to_the_decimals_the_ledger_holds_and_never_fewer_than_two(): void
    {
        $currency = $this->currency(onePerSettlement: true);

        $this->assertSame(
            ['12.345', '100.00', '10.1235', '0.30', '1,234,567.50', '-5.50', '12.30', '0.0001'],
            array_map(static fn (string|int|float $amount): string => $currency->amount(amount: $amount), [12.345, 100, 10.1235, 0.1 + 0.2, 1234567.5, -5.5, 12.30004, '0.0001']),
        );
    }
}
