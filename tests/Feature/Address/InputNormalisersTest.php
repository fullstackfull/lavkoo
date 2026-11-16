<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressText;
use App\Services\Address\NationalAddress;
use App\Services\Fulfillment\Support\SaudiPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a customer types is read the way the customer meant it, whatever keyboard it came from, and never as something else:
 * the National Address, the Saudi mobile number and the names of places.
 */
class InputNormalisersTest extends TestCase
{
    #[DataProvider('nationalAddresses')]
    public function test_a_national_address_is_accepted_only_in_the_shape_spl_writes_it(string $typed, ?string $canonical): void
    {
        $this->assertSame($canonical, NationalAddress::canonical($typed));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function nationalAddresses(): array
    {
        return [
            'as issued' => ['RAGI2929', 'RAGI2929'],
            'lower case, spaces and a hyphen' => ['ragi - 2929', 'RAGI2929'],
            'arabic-indic digits' => ['RAGI٢٩٢٩', 'RAGI2929'],
            'persian digits' => ['RAGI۲۹۲۹', 'RAGI2929'],
            'full-width letters and digits' => ['ＲＡＧＩ２９２９', 'RAGI2929'],
            'a sharp s is not "SS"' => ['ßAB1234', null],
            'a long s is not an "S"' => ['ſAGI2929', null],
            'a dotless i is not an "I"' => ['ıAGI2929', null],
            'a ligature is not two letters' => ['ﬁAB1234', null],
            'three letters' => ['RAG2929', null],
            'five digits' => ['RAGI29290', null],
            'blank' => ['  ', null],
            'an arabic letter mark' => ["\u{061C}RAGI2929", 'RAGI2929'],
            'an en dash' => ["RAGI\u{2013}2929", 'RAGI2929'],
            'a non-breaking hyphen' => ["RAGI\u{2011}2929", 'RAGI2929'],
            'a soft hyphen' => ["RAGI\u{00AD}2929", 'RAGI2929'],
            'a word joiner' => ["RAGI\u{2060}2929", 'RAGI2929'],
            'a non-breaking space' => ["RAGI\u{00A0}2929", 'RAGI2929'],
            'an arabic letter is still not a letter of the code' => ["\u{0631}AGI2929", null],
        ];
    }

    #[DataProvider('mobileNumbers')]
    public function test_a_saudi_mobile_number_is_read_in_every_script_a_keyboard_types_digits_in(string $typed, ?string $national): void
    {
        $this->assertSame($national, SaudiPhone::normalize($typed));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function mobileNumbers(): array
    {
        return [
            'national format' => ['0551234567', '0551234567'],
            'international' => ['+966 55 123 4567', '0551234567'],
            'double zero' => ['00966551234567', '0551234567'],
            'arabic-indic digits' => ['٠٥٥١٢٣٤٥٦٧', '0551234567'],
            'persian and urdu digits' => ['۰۵۵۱۲۳۴۵۶۷', '0551234567'],
            'full-width digits' => ['０５５１２３４５６７', '0551234567'],
            'international with the trunk zero kept' => ['+9660551234567', '0551234567'],
            'the country code without a plus' => ['966551234567', '0551234567'],
            'without the leading zero' => ['551234567', '0551234567'],
            'international with hyphens' => ['+966-55-123-4567', '0551234567'],
            'national with brackets and a hyphen' => ['(055) 123-4567', '0551234567'],
            'a landline is not a mobile' => ['0112345678', null],
            'a prefix no mobile has' => ['0451234567', null],
            'too short' => ['055123', null],
            'cut short' => ['05512345', null],
            'a digit too many' => ['+9665512345678', null],
            'blank' => ['', null],
        ];
    }

    #[DataProvider('spellingsOfOneNumber')]
    public function test_two_spellings_are_one_number_only_when_they_name_the_same_subscriber(string $first, string $second, bool $same): void
    {
        $this->assertSame($same, SaudiPhone::isSameNumber(first: $first, second: $second));
        $this->assertSame($same, SaudiPhone::isSameNumber(first: $second, second: $first));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function spellingsOfOneNumber(): array
    {
        return [
            'international and national' => ['+966551234567', '0551234567', true],
            'the trunk zero kept after the country code' => ['+9660551234567', '+966551234567', true],
            'double zero and spaces' => ['00966 55 123 4567', '+966551234567', true],
            'without the plus' => ['966551234567', '+966551234567', true],
            'arabic-indic digits' => ['٠٥٥١٢٣٤٥٦٧', '+966551234567', true],
            'another subscriber' => ['+966551234568', '+966551234567', false],
            'the same digits under another country code' => ['+971551234567', '+966551234567', false],
            'a number that only ends like the other' => ['+9661234567', '+966551234567', false],
            'a piece of a Saudi number' => ['551234', '+966551234567', false],
            'a landline is not a mobile' => ['0112345678', '+966551234567', false],
            'another Gulf number with the trunk zero kept' => ['+971 0 50 123 4567', '+971501234567', true],
            'another Gulf number with double zero' => ['00971 50 123 4567', '+971501234567', true],
            'another Gulf number under another country code' => ['+965501234567', '+971501234567', false],
            'a foreign number whose digits read as a Saudi mobile' => ['+508551234', '+966508551234', false],
            'another foreign number whose digits read as a Saudi mobile' => ['+597123456', '+966597123456', false],
            'a Saudi number is still a Saudi number in national form' => ['+966508551234', '0508551234', true],
            'a national number is not the number of another country' => ['0501234567', '+971501234567', false],
            'other countries by their digits' => ['+1 (500) 555-0006', '+15005550006', true],
            'other countries are not matched by a piece' => ['5550006', '+15005550006', false],
            'a pattern' => ['%', '+15005550006', false],
            'nothing against a number' => ['', '+15005550006', false],
            'nothing against nothing' => ['', '', false],
            'punctuation against punctuation' => ['+-()', '+-()', false],
        ];
    }

    #[DataProvider('spellingsOfOnePlace')]
    public function test_one_place_has_one_key_whatever_the_keyboard(string $standard, string $variant): void
    {
        $this->assertNotSame('', AddressText::key($standard));
        $this->assertSame(AddressText::key($standard), AddressText::key($variant));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function spellingsOfOnePlace(): array
    {
        return [
            'persian yeh' => ['الرياض', 'الریاض'],
            'persian kaf and yeh' => ['الكويت', 'الکویت'],
            'heh goal' => ['الخبر الشمالية', 'الخبر الشماليہ'],
            'heh doachashmee' => ['مكة', 'مكھ'],
            'persian digits in a name' => ['حي 12', 'حي ۱۲'],
            'full-width digits in a name' => ['حي 12', 'حي １２'],
            'arabic-indic digits in a name' => ['حي 12', 'حي ١٢'],
        ];
    }

    public function test_two_different_places_do_not_share_a_key_because_of_the_folding(): void
    {
        $this->assertNotSame(AddressText::key('الرياض'), AddressText::key('الكويت'));
        $this->assertNotSame(AddressText::key('حي 12'), AddressText::key('حي 21'));
    }
}
