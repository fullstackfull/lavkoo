<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\RecoveryCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RecoveryCodesTest extends TestCase
{
    public function test_ten_codes_are_made_each_of_two_groups_of_five(): void
    {
        $codes = RecoveryCodes::generate();

        $this->assertCount(10, $codes);
        $this->assertSame(RecoveryCodes::COUNT, 10);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/', $code);
        }
    }

    public function test_the_alphabet_has_no_letter_that_is_easily_taken_for_a_digit_and_no_digit_that_is_taken_for_a_letter(): void
    {
        $seen = implode('', RecoveryCodes::generate());
        for ($round = 0; $round < 60; $round++) {
            $seen .= implode('', RecoveryCodes::generate());
        }

        $this->assertSame(0, preg_match('/[ilo01]/', $seen));
        foreach (str_split(RecoveryCodes::ALPHABET) as $character) {
            $this->assertStringContainsString($character, $seen, "{$character} is a character a code can have");
        }
        $this->assertSame('abcdefghjkmnpqrstuvwxyz23456789', RecoveryCodes::ALPHABET);
        $this->assertSame(31, strlen(RecoveryCodes::ALPHABET));
        $this->assertSame(10, RecoveryCodes::LENGTH);
    }

    public function test_the_codes_differ_from_one_another_and_from_one_call_to_the_next(): void
    {
        $first = RecoveryCodes::generate();
        $second = RecoveryCodes::generate();

        $this->assertCount(10, array_unique($first));
        $this->assertNotSame($first, $second);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function typedCodes(): array
    {
        return [
            'as shown' => ['abcde-23456', 'abcde23456'],
            'without the hyphen' => ['abcde23456', 'abcde23456'],
            'in capitals' => ['ABCDE-23456', 'abcde23456'],
            'with spaces about it and in it' => [" abcde 23456\n", 'abcde23456'],
            'with a hyphen in another place' => ['abc-de23456', 'abcde23456'],
            'nine characters' => ['abcde-2345', null],
            'eleven characters' => ['abcde-234567', null],
            'a letter that is not in the alphabet' => ['abcdi-23456', null],
            'a zero' => ['abcde-23450', null],
            'a punctuation mark' => ['abcde-2345!', null],
            'nothing' => ['', null],
            'a six digit code' => ['123456', null],
            'a multi-byte character' => ['abcde-2345é', null],
        ];
    }

    #[DataProvider('typedCodes')]
    public function test_a_typed_code_is_made_ready_to_compare_or_refused_as_not_a_code(string $typed, ?string $expected): void
    {
        $this->assertSame($expected, RecoveryCodes::normalized(typed: $typed));
    }

    public function test_a_code_is_kept_as_an_hmac_under_a_key_that_comes_from_the_application_key(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $hash = RecoveryCodes::hash(normalized: 'abcde23456');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, RecoveryCodes::hash(normalized: 'abcde23456'), 'the same code, the same hash');
        $this->assertNotSame($hash, RecoveryCodes::hash(normalized: 'abcde23457'));
        $this->assertNotSame(hash('sha256', 'abcde23456'), $hash, 'not a bare hash, which could be guessed offline');
        $this->assertNotSame(hash_hmac('sha256', 'abcde23456', str_repeat('k', 32)), $hash, 'not the application key itself as the key either: a key of its own purpose is derived from it');

        config(['app.key' => 'base64:' . base64_encode(str_repeat('j', 32))]);
        $this->assertNotSame($hash, RecoveryCodes::hash(normalized: 'abcde23456'), 'another application key, another hash');
    }

    public function test_a_plain_application_key_is_used_as_it_is_and_none_at_all_is_refused(): void
    {
        config(['app.key' => 'a-key-that-is-not-base64-encoded']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', RecoveryCodes::hash(normalized: 'abcde23456'));

        config(['app.key' => '']);
        $this->expectException(RuntimeException::class);
        RecoveryCodes::hash(normalized: 'abcde23456');
    }
}
