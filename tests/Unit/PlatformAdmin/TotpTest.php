<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one-time password, against the published vectors: RFC 4226 appendix D for the counter, RFC 6238 appendix B for the time (the SHA-1 secret
 * is the ASCII text "12345678901234567890" in both).
 */
class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    /** The two adjacent steps (100 and 101) at which this secret shows the same six digits, found by searching: the one case where two steps of a window match. */
    private const TWIN_SECRET_HEX = '511c1634e743e395f8787e81ebf2904baa181b55';

    private const TWIN_CODE = '576141';

    /** @return array<string, array{0: int, 1: string}> RFC 4226 appendix D */
    public static function hotpVectors(): array
    {
        return [
            'counter 0' => [0, '755224'],
            'counter 1' => [1, '287082'],
            'counter 2' => [2, '359152'],
            'counter 3' => [3, '969429'],
            'counter 4' => [4, '338314'],
            'counter 5' => [5, '254676'],
            'counter 6' => [6, '287922'],
            'counter 7' => [7, '162583'],
            'counter 8' => [8, '399871'],
            'counter 9' => [9, '520489'],
        ];
    }

    #[DataProvider('hotpVectors')]
    public function test_the_code_of_a_counter_is_the_one_of_rfc_4226(int $counter, string $expected): void
    {
        $this->assertSame($expected, Totp::codeAt(secret: self::RFC_SECRET, step: $counter));
    }

    /** @return array<string, array{0: int, 1: string}> RFC 6238 appendix B, SHA-1, eight digits */
    public static function totpVectors(): array
    {
        return [
            'T = 59' => [59, '94287082'],
            'T = 1111111109' => [1111111109, '07081804'],
            'T = 1111111111' => [1111111111, '14050471'],
            'T = 1234567890' => [1234567890, '89005924'],
            'T = 2000000000' => [2000000000, '69279037'],
            'T = 20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('totpVectors')]
    public function test_the_code_of_a_moment_is_the_one_of_rfc_6238(int $timestamp, string $expected): void
    {
        $step = Totp::stepAt(timestamp: $timestamp);

        $this->assertSame($expected, Totp::codeAt(secret: self::RFC_SECRET, step: $step, digits: 8));
        $this->assertSame(substr($expected, -6), Totp::codeAt(secret: self::RFC_SECRET, step: $step), 'six digits are the last six of the eight');
    }

    public function test_a_code_keeps_its_leading_zeros(): void
    {
        $this->assertSame('005924', Totp::codeAt(secret: self::RFC_SECRET, step: Totp::stepAt(timestamp: 1234567890)));
        $this->assertSame(6, strlen(Totp::codeAt(secret: self::RFC_SECRET, step: Totp::stepAt(timestamp: 1234567890))));
    }

    public function test_a_step_is_thirty_seconds(): void
    {
        $this->assertSame(0, Totp::stepAt(timestamp: 0));
        $this->assertSame(0, Totp::stepAt(timestamp: 29));
        $this->assertSame(1, Totp::stepAt(timestamp: 30));
        $this->assertSame(1, Totp::stepAt(timestamp: 59));
        $this->assertSame(2, Totp::stepAt(timestamp: 60));
        $this->assertSame(Totp::PERIOD_SECONDS, 30);
        $this->assertSame(Totp::DIGITS, 6);
    }

    public function test_the_step_a_code_belongs_to_is_found_one_step_either_side_and_no_further(): void
    {
        $at = static fn (int $step): string => Totp::codeAt(secret: self::RFC_SECRET, step: $step);

        // 59 s is step 1. Steps 0 and 2 are the one before and the one after; 3 and above are out of the window.
        $this->assertSame(1, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(1), timestamp: 59));
        $this->assertSame(0, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(0), timestamp: 59));
        $this->assertSame(2, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(2), timestamp: 59));
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $at(3), timestamp: 59));

        // 1 800 s is step 60: the step two before (58) and the step two after (62) are not accepted either.
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $at(58), timestamp: 1800));
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $at(62), timestamp: 1800));
        $this->assertSame(59, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(59), timestamp: 1800));
        $this->assertSame(61, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(61), timestamp: 1800));
    }

    public function test_the_window_is_the_one_asked_for(): void
    {
        $at = static fn (int $step): string => Totp::codeAt(secret: self::RFC_SECRET, step: $step);

        $this->assertSame(1, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(1), timestamp: 59, window: 0));
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $at(0), timestamp: 59, window: 0));
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $at(2), timestamp: 59, window: 0));
        $this->assertSame(3, Totp::matchingStep(secret: self::RFC_SECRET, code: $at(3), timestamp: 59, window: 2));
        $this->assertSame(Totp::DEFAULT_WINDOW, 1);
    }

    public function test_when_two_steps_of_the_window_show_the_same_code_the_later_one_is_the_step(): void
    {
        $secret = (string) hex2bin(self::TWIN_SECRET_HEX);
        $this->assertSame(self::TWIN_CODE, Totp::codeAt(secret: $secret, step: 100));
        $this->assertSame(self::TWIN_CODE, Totp::codeAt(secret: $secret, step: 101));

        // 3 000 s is step 100: both 100 and 101 are in its window. The later one wins, so that a code that was accepted once cannot be accepted again as the earlier of the two.
        $this->assertSame(101, Totp::matchingStep(secret: $secret, code: self::TWIN_CODE, timestamp: 3000));
        // 3 060 s is step 102: 101 is in the window, 100 is not.
        $this->assertSame(101, Totp::matchingStep(secret: $secret, code: self::TWIN_CODE, timestamp: 3060));
    }

    /** @return array<string, array{string}> */
    public static function notACode(): array
    {
        return [
            'empty' => [''],
            'five digits' => ['28708'],
            'seven digits' => ['2870820'],
            'letters' => ['abcdef'],
            'a space' => ['287 082'],
            'a sign' => ['+87082'],
            'a decimal point' => ['287.82'],
            'the eight digit code of the same step' => ['94287082'],
        ];
    }

    #[DataProvider('notACode')]
    public function test_something_that_is_not_six_digits_matches_no_step(string $code): void
    {
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: $code, timestamp: 59));
    }

    public function test_a_wrong_code_matches_no_step(): void
    {
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: '000000', timestamp: 59));
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET, code: '287083', timestamp: 59));
    }

    public function test_a_code_of_another_secret_matches_no_step(): void
    {
        $this->assertNull(Totp::matchingStep(secret: self::RFC_SECRET . '1', code: '287082', timestamp: 59));
    }

    public function test_a_new_secret_is_twenty_random_bytes_that_differ_every_time(): void
    {
        $first = Totp::newSecret();
        $second = Totp::newSecret();

        $this->assertSame(20, strlen($first));
        $this->assertSame(Totp::SECRET_BYTES, 20);
        $this->assertNotSame($first, $second);
    }

    public function test_the_address_an_authenticator_app_reads_names_the_issuer_the_account_and_the_parameters(): void
    {
        $uri = Totp::otpauthUri(base32Secret: 'JBSWY3DPEHPK3PXP', account: 'owner@example.test', issuer: 'Example Platform');

        $this->assertSame('otpauth://totp/Example%20Platform:owner%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=Example%20Platform&algorithm=SHA1&digits=6&period=30', $uri);
    }

    public function test_an_issuer_or_account_with_a_colon_or_a_space_cannot_break_the_address(): void
    {
        $uri = Totp::otpauthUri(base32Secret: 'JBSWY3DPEHPK3PXP', account: 'a:b c', issuer: 'x&y=z');

        $this->assertSame('otpauth://totp/x%26y%3Dz:a%3Ab%20c?secret=JBSWY3DPEHPK3PXP&issuer=x%26y%3Dz&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
