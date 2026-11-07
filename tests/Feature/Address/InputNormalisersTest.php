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
