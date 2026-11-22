<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Base32Test extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> the test vectors of RFC 4648 section 10, without the padding this class never writes */
    public static function rfcVectors(): array
    {
        return [
            'nothing' => ['', ''],
            'f' => ['f', 'MY'],
            'fo' => ['fo', 'MZXQ'],
            'foo' => ['foo', 'MZXW6'],
            'foob' => ['foob', 'MZXW6YQ'],
            'fooba' => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_encodes_the_vectors_of_the_rfc_without_padding(string $plain, string $encoded): void
    {
        $this->assertSame($encoded, Base32::encode(bytes: $plain));
    }

    #[DataProvider('rfcVectors')]
    public function test_it_decodes_the_vectors_of_the_rfc(string $plain, string $encoded): void
    {
        $this->assertSame($plain, Base32::decode(text: $encoded));
    }

    public function test_the_padding_of_the_rfc_is_accepted_when_decoding(): void
    {
        $this->assertSame('foobar', Base32::decode(text: 'MZXW6YTBOI======'));
        $this->assertSame('fooba', Base32::decode(text: 'MZXW6YTB'));
        $this->assertSame('f', Base32::decode(text: 'MY======'));
    }

    public function test_lower_case_and_the_spaces_an_authenticator_app_groups_digits_with_are_accepted(): void
    {
        $this->assertSame('foobar', Base32::decode(text: 'mzxw 6ytb oi'));
        $this->assertSame('foobar', Base32::decode(text: "MZXW6YTBOI\n"));
    }

    /** @return array<string, array{string}> */
    public static function notBase32(): array
    {
        return [
            'a digit that is not in the alphabet' => ['MZXW1YTB'],
            'a letter that is not either' => ['MZXW6YT!'],
            'a hyphen' => ['MZXW-6YTB'],
            'the padding in the middle' => ['MZ=XW6YTB'],
            'a length no encoding has (1)' => ['M'],
            'a length no encoding has (3)' => ['MZX'],
            'a length no encoding has (6)' => ['MZXW6Y'],
            'a multi-byte character' => ['MZXW6YTÉ'],
        ];
    }

    #[DataProvider('notBase32')]
    public function test_text_that_is_not_base32_decodes_to_nothing_at_all(string $text): void
    {
        $this->assertNull(Base32::decode(text: $text));
    }

    public function test_every_byte_value_survives_a_round_trip_at_every_length(): void
    {
        for ($length = 0; $length <= 33; $length++) {
            $bytes = '';
            for ($index = 0; $index < $length; $index++) {
                $bytes .= chr(($index * 37 + $length * 11) % 256);
            }

            $this->assertSame($bytes, Base32::decode(text: Base32::encode(bytes: $bytes)), "length {$length}");
        }

        $all = implode('', array_map('chr', range(0, 255)));
        $this->assertSame($all, Base32::decode(text: Base32::encode(bytes: $all)));
    }

    public function test_twenty_bytes_are_thirty_two_characters(): void
    {
        $this->assertSame(32, strlen(Base32::encode(bytes: str_repeat("\xff", 20))));
        $this->assertSame('77777777777777777777777777777777', Base32::encode(bytes: str_repeat("\xff", 20)));
        $this->assertSame(str_repeat('A', 32), Base32::encode(bytes: str_repeat("\x00", 20)));
    }
}
