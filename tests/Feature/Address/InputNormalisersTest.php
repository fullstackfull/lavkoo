<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressText;
use App\Services\Address\NationalAddress;
use App\Services\Fulfillment\Support\SaudiPhone;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * What a customer types is read the way the customer meant it, whatever keyboard it came from, and never as something else:
 * the National Address, the Saudi mobile number and the names of places.
 */
class InputNormalisersTest extends TestCase
{
    /** Country codes that are not on the trunk zero list, among them the neighbours of the ones that are. */
    private const OTHER_COUNTRY_CODES = ['1', '7', '30', '34', '39', '45', '47', '48', '52', '56', '57', '65', '211', '216', '225', '351', '352', '420', '960', '972', '977', '992'];

    /** The digits 0 to 9 as ASCII, Arabic-Indic, Persian and full-width digits. */
    private const DIGIT_SCRIPTS = ['0123456789', '٠١٢٣٤٥٦٧٨٩', '۰۱۲۳۴۵۶۷۸۹', '０１２３４５６７８９'];

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
            'a Syrian number with the trunk zero kept after the country code' => ['+9630912345678', '+963912345678', true],
            'a Saudi number that is no mobile with the trunk zero kept after the country code' => ['+966 0 11 234 5678', '+966112345678', true],
            'a number of the same country with another last digit' => ['+9630912345679', '+963912345678', false],
            'two trunk zeros are not one' => ['+96300912345678', '+963912345678', false],
            'a zero further into the number is part of it' => ['+963 9 0 12345678', '+963912345678', false],
            'a zero before the country code is part of the number' => ['0963912345678', '+963912345678', false],
            'a national number is not the number of the country' => ['0912345678', '+963912345678', false],
            'only the country code against a number of the country' => ['+963', '+963912345678', false],
            'nothing against a number of a country that writes a trunk zero' => ['', '+963912345678', false],
            'the same digits under another country that writes a trunk zero' => ['+9610912345678', '+963912345678', false],
            'a Saudi number with the same trailing digits' => ['+963 0 501234567', '+966501234567', false],
            'a Saudi number in national form with the same trailing digits' => ['+963 0 501234567', '0501234567', false],
            'Egypt is not Morocco' => ['+2001012345678', '+2121012345678', false],
            'Maldives is not Yemen with a zero dropped' => ['+960 7123456', '+967 123456', false],
            'a zero inside the mobile prefix of Egypt is not a trunk zero' => ['+201012345678', '+20112345678', false],
            'a country that keeps its zero in the number' => ['+39 06 1234 5678', '+39 6 1234 5678', false],
            'a country that keeps its zero is one number whichever way it is written' => ['+39 06 1234 5678', '0039 06 1234 5678', true],
            'digits of another script are digits' => ['+971٥٠١٢٣٤٥٦٧', '+971501234567', true],
            'digits of another script are not nothing' => ['+971٥٠١٢٣٤٥٦٧', '+971', false],
            'two numbers in digits of another script are two numbers' => ['+971٥٠١٢٣٤٥٦٧', '+971٣٣٣٣٣٣٣٣٣', false],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> every country code of the trunk zero list with a mobile number of that country as Google writes it after the code */
    public static function countriesThatWriteATrunkZero(): array
    {
        return [
            'Egypt' => ['20', '1012345678'],
            'Turkey' => ['90', '5321234567'],
            'Morocco' => ['212', '612345678'],
            'Algeria' => ['213', '551234567'],
            'Libya' => ['218', '921234567'],
            'Sudan' => ['249', '911234567'],
            'Lebanon' => ['961', '71123456'],
            'Jordan' => ['962', '791234567'],
            'Syria' => ['963', '912345678'],
            'Iraq' => ['964', '7901234567'],
            'Kuwait' => ['965', '50012345'],
            'Saudi Arabia' => ['966', '551234567'],
            'Yemen' => ['967', '712345678'],
            'Oman' => ['968', '91234567'],
            'Palestine' => ['970', '591234567'],
            'the Emirates' => ['971', '501234567'],
            'Bahrain' => ['973', '36001234'],
            'Qatar' => ['974', '33123456'],
        ];
    }

    #[DataProvider('countriesThatWriteATrunkZero')]
    public function test_a_zero_kept_after_the_country_code_is_the_same_number_in_every_spelling(string $countryCode, string $national): void
    {
        $google = "+{$countryCode}{$national}";
        $registered = [
            'the trunk zero kept' => "+{$countryCode}0{$national}",
            'spaces and a hyphen' => "+{$countryCode} 0" . substr($national, 0, 3) . '-' . substr($national, 3),
            'the trunk zero in brackets' => "+{$countryCode} (0) {$national}",
            'double zero for the plus' => "00{$countryCode}0{$national}",
            'no plus' => "{$countryCode}0{$national}",
            'arabic-indic digits' => strtr("+{$countryCode}0{$national}", array_combine(str_split(self::DIGIT_SCRIPTS[0]), mb_str_split(self::DIGIT_SCRIPTS[1]))),
        ];

        foreach ($registered as $spelling => $number) {
            $this->assertTrue(SaudiPhone::isSameNumber(first: $number, second: $google), "{$spelling}: {$number} is the number {$google}");
            $this->assertTrue(SaudiPhone::isSameNumber(first: $google, second: $number), "{$spelling}: {$google} is the number {$number}");
        }
    }

    public function test_the_trunk_zero_list_is_the_one_tested_and_no_code_of_it_starts_another(): void
    {
        $listed = (new ReflectionClass(SaudiPhone::class))->getConstant('COUNTRY_CODES_WITH_A_TRUNK_ZERO');

        $this->assertIsArray($listed);
        $this->assertEqualsCanonicalizing(array_column(self::countriesThatWriteATrunkZero(), 0), $listed);
        foreach ($listed as $countryCode) {
            $startedBy = array_filter($listed, static fn (string $other): bool => $other !== $countryCode && str_starts_with($other, $countryCode));
            $this->assertSame([], $startedBy, "{$countryCode} starts another code of the list, so a number could match two of them");
        }
    }

    public function test_seeded_random_pairs_are_one_number_exactly_when_the_rule_says_so_in_both_orders(): void
    {
        $seed = 20261117;
        $draw = static function (int $below) use (&$seed): int {
            $seed ^= ($seed << 13) & 0xFFFFFFFF;
            $seed ^= $seed >> 17;
            $seed ^= ($seed << 5) & 0xFFFFFFFF;

            return $seed % $below;
        };
        $wrong = [];

        for ($pair = 0; $pair < 5000; $pair++) {
            $first = self::randomNumber(draw: $draw);
            [$second, $differs] = self::madeFrom(number: $first, draw: $draw);
            $spelledFirst = self::spelled(number: $first, draw: $draw);
            $spelledSecond = self::spelled(number: $second, draw: $draw);
            $verdict = SaudiPhone::isSameNumber(first: $spelledFirst, second: $spelledSecond);

            if ($verdict !== self::isOneNumber(first: $first, second: $second)
                || $verdict !== SaudiPhone::isSameNumber(first: $spelledSecond, second: $spelledFirst)
                || ($verdict && $differs)) {
                $wrong[] = "{$spelledFirst} | {$spelledSecond}";
            }
        }

        $this->assertSame([], array_slice($wrong, 0, 5), count($wrong) . ' of 5000 pairs were answered against the rule');
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

    /** @return array{0: string, 1: int, 2: string} a country code, how many zeros are written straight after it, and 6 to 10 national digits that do not start with a zero */
    private static function randomNumber(Closure $draw): array
    {
        $countryCodes = array_merge(array_column(self::countriesThatWriteATrunkZero(), 0), self::OTHER_COUNTRY_CODES);
        $national = (string) (1 + $draw(below: 9));
        for ($length = 5 + $draw(below: 5); $length > 0; $length--) {
            $national .= $draw(below: 10);
        }

        return [$countryCodes[$draw(below: count($countryCodes))], $draw(below: 2), $national];
    }

    /**
     * A number made from another one in one of ten ways, and whether that way changes its national digits for certain.
     *
     * @param array{0: string, 1: int, 2: string} $number
     * @return array{0: array{0: string, 1: int, 2: string}, 1: bool}
     */
    private static function madeFrom(array $number, Closure $draw): array
    {
        [$countryCode, $zeros, $national] = $number;
        $last = strlen($national) - 1;
        $withDigitChanged = static fn (int $position): string => substr_replace($national, (string) (((int) $national[$position] + 1 + $draw(below: 9)) % 10), $position, 1);

        return match ($draw(below: 10)) {
            0 => [[$countryCode, $zeros, $national], false],
            1 => [[$countryCode, 1 - $zeros, $national], false],
            2 => [[$countryCode, $zeros, $withDigitChanged(position: $last)], true],
            3 => [[$countryCode, $zeros, $withDigitChanged(position: 1 + $draw(below: $last - 1))], true],
            4 => [[$countryCode, $zeros, $national . $draw(below: 10)], true],
            5 => [[$countryCode, $zeros, substr($national, 0, $last)], true],
            6 => [[self::randomNumber(draw: $draw)[0], $zeros, $national], false],
            7 => [[$countryCode, $zeros, substr($national, 0, 1) . '0' . substr($national, 1)], true],
            8 => [[$countryCode, 2, $national], true],
            9 => [self::randomNumber(draw: $draw), false],
        };
    }

    /** @param array{0: string, 1: int, 2: string} $number */
    private static function spelled(array $number, Closure $draw): string
    {
        [$countryCode, $zeros, $national] = $number;
        $plus = $draw(below: 2) === 0;
        $script = mb_str_split(self::DIGIT_SCRIPTS[$draw(below: count(self::DIGIT_SCRIPTS))]);
        $spelling = $plus ? '+' : '';
        foreach (str_split(($plus ? '' : '00') . $countryCode . str_repeat('0', $zeros) . $national) as $digit) {
            $spelling .= $script[(int) $digit] . [' ', '-', '(', ')', '', '', ''][$draw(below: 7)];
        }

        return $spelling;
    }

    /**
     * The rule the answer is held to, written without the code under test: a number is its country code and what follows it, and one zero
     * straight after a code of the list is not part of what follows. After any other code a zero is part of the number.
     *
     * @param array{0: string, 1: int, 2: string} $first
     * @param array{0: string, 1: int, 2: string} $second
     */
    private static function isOneNumber(array $first, array $second): bool
    {
        $listed = array_column(self::countriesThatWriteATrunkZero(), 0);
        $identity = static function (array $number) use ($listed): array {
            [$countryCode, $zeros, $national] = $number;
            $zeros -= in_array($countryCode, $listed, true) && $zeros > 0 ? 1 : 0;

            return [$countryCode, str_repeat('0', $zeros) . $national];
        };

        return $identity(number: $first) === $identity(number: $second);
    }
}
