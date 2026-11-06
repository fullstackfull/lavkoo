<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\CurrencyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The store's spelling of a currency ("KSA" for the riyal) against the ISO code providers know ("SAR"). */
class CurrencyCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.currency' => 'SAR', 'fulfillment.currency_aliases' => ['KSA' => 'SAR']]);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function codes(): iterable
    {
        yield 'the store\'s riyal' => ['KSA', 'SAR'];
        yield 'lower case' => ['ksa', 'SAR'];
        yield 'spaces around it' => [' KSA ', 'SAR'];
        yield 'the ISO code' => ['SAR', 'SAR'];
        yield 'another currency is untouched' => ['usd', 'USD'];
        yield 'empty' => ['', null];
        yield 'only spaces' => ['  ', null];
        yield 'null' => [null, null];
        yield 'an array' => [['KSA'], null];
    }

    #[DataProvider('codes')]
    public function test_the_stores_spelling_becomes_the_iso_code(mixed $code, ?string $expected): void
    {
        $this->assertSame($expected, CurrencyCode::canonical($code));
    }

    public function test_without_an_alias_the_stores_riyal_is_just_another_code(): void
    {
        config(['fulfillment.currency_aliases' => []]);

        $this->assertSame('KSA', CurrencyCode::canonical('ksa'));
        $this->assertSame(['SAR'], CurrencyCode::spellings('SAR'));
    }

    public function test_an_alias_is_read_without_regard_to_case_or_spaces_and_a_code_mapped_to_itself_is_no_alias(): void
    {
        config(['fulfillment.currency_aliases' => [' ksa ' => ' sar ', 'SAR' => 'SAR', '' => 'SAR', 'XXX' => '']]);

        $this->assertSame('SAR', CurrencyCode::canonical('KSA'));
        $this->assertSame('XXX', CurrencyCode::canonical('XXX'));
        $this->assertSame(['SAR', 'KSA'], CurrencyCode::spellings('SAR'));
    }

    public function test_an_alias_map_that_would_loop_or_chain_is_not_followed_and_one_that_is_not_text_is_ignored(): void
    {
        config(['fulfillment.currency_aliases' => ['KSA' => 'SAR', 'SAR' => 'KSA', 'AAA' => 'BBB', 'BBB' => 'CCC', 'DDD' => ['EEE'], 'FFF' => null]]);

        $this->assertSame('KSA', CurrencyCode::canonical('KSA'), 'KSA and SAR cannot both be a spelling of the other');
        $this->assertSame('SAR', CurrencyCode::canonical('SAR'));
        $this->assertSame('AAA', CurrencyCode::canonical('AAA'), 'a chain is not followed');
        $this->assertSame('CCC', CurrencyCode::canonical('BBB'));
        $this->assertSame('DDD', CurrencyCode::canonical('DDD'));
        $this->assertSame('FFF', CurrencyCode::canonical('FFF'));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function configured(): iterable
    {
        yield 'the default' => [null, 'SAR'];
        yield 'the ISO code' => ['SAR', 'SAR'];
        yield 'lower case' => ['sar', 'SAR'];
        yield 'the store\'s spelling' => ['KSA', 'SAR'];
        yield 'the store\'s spelling, loosely typed' => [' ksa ', 'SAR'];
        yield 'empty' => ['', 'SAR'];
        yield 'another currency' => ['usd', 'USD'];
    }

    #[DataProvider('configured')]
    public function test_the_currency_providers_are_paid_in_is_always_an_iso_code(mixed $setting, string $expected): void
    {
        config(['fulfillment.currency' => $setting]);

        $this->assertSame($expected, CurrencyCode::provider());
    }

    public function test_every_spelling_of_a_currency_lists_the_iso_code_first(): void
    {
        config(['fulfillment.currency_aliases' => ['KSA' => 'SAR', 'SAU' => 'SAR', 'EGP2' => 'EGP']]);

        $this->assertSame(['SAR', 'KSA', 'SAU'], CurrencyCode::spellings('SAR'));
        $this->assertSame(['SAR', 'KSA', 'SAU'], CurrencyCode::spellings('ksa'));
        $this->assertSame(['USD'], CurrencyCode::spellings('USD'));
    }
}
