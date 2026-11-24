<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\MarketPhone;
use App\Services\Fulfillment\Support\SaudiPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-15: the phone number a courier is given follows the customer's own country, and the Saudi rule is untouched.
 *
 * Fulfilment holds a shipment whose phone number it cannot read, which is the right answer to a number a courier could not call. It knew
 * one rule — a Saudi mobile — so the five other markets would have had every order held: a Kuwaiti mobile is eight digits with no trunk
 * zero and can never match a Saudi pattern.
 *
 * The dialling code and the national length are checked, because those are facts that do not move. Which prefixes inside that length are
 * mobile is left to configuration: regulators allocate new ranges and portability means a prefix does not name an operator, so a guessed
 * prefix list would hold real orders. Saudi Arabia keeps the pattern it already had.
 */
class MarketPhoneTest extends TestCase
{
    /** @return array<string, array{string, string, string}> market, what a customer types, what the courier is given */
    public static function numbersOfTheSixMarkets(): array
    {
        return [
            'a Saudi number, nationally' => ['SA', '0512345678', '0512345678'],
            'a Saudi number, internationally' => ['SA', '+966512345678', '0512345678'],
            'a Saudi number with the code and a trunk zero' => ['SA', '00966 (0)51 234 5678', '0512345678'],
            'an Emirati number' => ['AE', '+971 50 123 4567', '0501234567'],
            'an Emirati number, nationally' => ['AE', '0501234567', '0501234567'],
            'a Kuwaiti number' => ['KW', '+965 5123 4567', '51234567'],
            'a Kuwaiti number, nationally' => ['KW', '51234567', '51234567'],
            'a Qatari number' => ['QA', '+974 3312 3456', '33123456'],
            'a Bahraini number' => ['BH', '+973 3212 3456', '32123456'],
            'an Omani number' => ['OM', '+968 9123 4567', '91234567'],
            'Arabic-Indic digits' => ['KW', '+٩٦٥ ٥١٢٣٤٥٦٧', '51234567'],
        ];
    }

    #[DataProvider('numbersOfTheSixMarkets')]
    public function test_a_number_of_each_market_reaches_its_courier_in_its_own_national_form(string $country, string $typed, string $given): void
    {
        $this->assertSame($given, MarketPhone::nationalNumberFor($country, $typed));
    }

    /** @return array<string, array{string, string}> */
    public static function numbersThatAreNotOfTheirMarket(): array
    {
        return [
            'a Kuwaiti number offered as Saudi' => ['SA', '+965 5123 4567'],
            'a Saudi landline' => ['SA', '0112345678'],
            'a Saudi mobile one digit short' => ['SA', '051234567'],
            'a Kuwaiti number one digit short' => ['KW', '5123456'],
            'a Kuwaiti number one digit long' => ['KW', '512345678'],
            'a number of no country at all' => ['KW', 'not a number'],
            'an empty spelling' => ['OM', ''],
        ];
    }

    #[DataProvider('numbersThatAreNotOfTheirMarket')]
    public function test_a_number_that_is_not_of_its_market_is_refused_so_the_shipment_is_held(string $country, string $typed): void
    {
        $this->assertNull(MarketPhone::nationalNumberFor($country, $typed));
    }

    /** A market the configuration says nothing about is a refusal rather than a guess at its dialling code. */
    public function test_a_market_the_configuration_does_not_know_is_refused(): void
    {
        $this->assertNull(MarketPhone::nationalNumberFor('ZZ', '+1 555 0100'));
        $this->assertNull(MarketPhone::nationalNumberFor('EG', '+20 10 1234 5678'));
    }

    /** No country named is the default market, which is every shipment of a Saudi-only installation. */
    public function test_a_shipment_that_names_no_country_is_read_by_the_default_market(): void
    {
        $this->assertSame('0512345678', MarketPhone::nationalNumberFor(null, '+966512345678'));
        $this->assertSame('0512345678', MarketPhone::nationalNumberFor('', '0512345678'));
        $this->assertNull(MarketPhone::nationalNumberFor(null, '+965 5123 4567'), 'and a Kuwaiti number is not a Saudi one');
    }

    /**
     * The Saudi answer is the one that already shipped, number for number: whatever a customer types, this class and the class fulfilment
     * has always used agree. If they ever disagree, a Saudi shipment has changed.
     */
    #[DataProvider('everySpellingTheSaudiRuleHasEverBeenGiven')]
    public function test_the_saudi_answer_is_the_one_that_already_shipped(string $typed): void
    {
        $this->assertSame(SaudiPhone::normalize($typed), MarketPhone::nationalNumberFor('SA', $typed));
    }

    /** @return array<string, array{string}> */
    public static function everySpellingTheSaudiRuleHasEverBeenGiven(): array
    {
        $spellings = [
            '0512345678', '512345678', '+966512345678', '00966512345678', '966512345678', '0096605 1234 5678',
            '+966 (0) 51-234-5678', '٠٥١٢٣٤٥٦٧٨', '۰۵۱۲۳۴۵۶۷۸', '０５１２３４５６７８',
            '0112345678', '051234567', '05123456789', '', 'not a number', '+971501234567', '+965 5123 4567', '05 1234 5678 ',
        ];

        return array_combine($spellings, array_map(static fn (string $spelling): array => [$spelling], $spellings));
    }

    public function test_the_dialling_code_of_a_market_is_its_own(): void
    {
        foreach (['SA' => '966', 'AE' => '971', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968'] as $country => $code) {
            $this->assertSame($code, MarketPhone::diallingCodeOf($country));
        }

        $this->assertNull(MarketPhone::diallingCodeOf('ZZ'));
    }

    /** An operator who reads their market's numbering plan can make its rule stricter, and the platform then enforces exactly that. */
    public function test_a_pattern_an_operator_configures_is_what_is_enforced(): void
    {
        $this->assertSame('51234567', MarketPhone::nationalNumberFor('KW', '51234567'), 'any eight-digit number while no pattern is given');

        config(['gcc.storefronts.phone.KW.pattern' => '/^[569]\d{7}$/']);

        $this->assertSame('51234567', MarketPhone::nationalNumberFor('KW', '51234567'));
        $this->assertNull(MarketPhone::nationalNumberFor('KW', '71234567'), 'and a number outside the configured ranges is held');
    }
}
