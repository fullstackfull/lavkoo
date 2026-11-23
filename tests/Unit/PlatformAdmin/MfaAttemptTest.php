<?php

namespace Tests\Unit\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Services\PlatformAdmin\MfaAttempt;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the second step makes of a request body, before anything is looked up with it: the sizes it is cut to, the shapes it accepts, and that nothing but
 * the body is read.
 */
class MfaAttemptTest extends TestCase
{
    private const PLACEHOLDER = '000000';

    public function test_a_code_is_an_attempt_for_a_code_with_its_spaces_taken_out(): void
    {
        $attempt = $this->attemptOf(['challenge' => 'lkpc_x', 'code' => ' 123 456 ']);

        $this->assertSame([MfaMethod::Totp, '123456', ''], [$attempt->method, $attempt->code, $attempt->recoveryCode]);
    }

    public function test_a_recovery_code_is_an_attempt_for_a_recovery_code_in_the_form_it_is_compared_in(): void
    {
        $attempt = $this->attemptOf(['challenge' => 'lkpc_x', 'recovery_code' => ' ABCDE-23456 ']);

        $this->assertSame([MfaMethod::RecoveryCode, 'abcde23456', self::PLACEHOLDER], [$attempt->method, $attempt->recoveryCode, $attempt->code]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function attemptsForNeitherOrBoth(): array
    {
        return [
            'neither' => [[]],
            'both' => [['code' => '123456', 'recovery_code' => 'abcde-23456']],
            'a code of five digits' => [['code' => '12345']],
            'a code of seven digits' => [['code' => '1234567']],
            'a code with something after its six digits' => [['code' => '123456x']],
            'a code with something in it' => [['code' => '12ab56']],
            'a code that is not text' => [['code' => 123456]],
            'a code that is a list' => [['code' => ['123456']]],
            'a recovery code that is too short' => [['recovery_code' => 'abcde']],
            'a recovery code that is not text' => [['recovery_code' => 2345623456]],
            'a code and a recovery code that is a list' => [['code' => '123456', 'recovery_code' => ['abcde-23456']]],
            'a code and a recovery code that is not text' => [['code' => '123456', 'recovery_code' => "abcde\xFF23456"]],
            'a code and a recovery code that is a number' => [['code' => '123456', 'recovery_code' => 0]],
            'a recovery code and a code that is a list' => [['recovery_code' => 'abcde-23456', 'code' => ['123456']]],
            'a recovery code and a code that is a number' => [['recovery_code' => 'abcde-23456', 'code' => 123456]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('attemptsForNeitherOrBoth')]
    public function test_an_attempt_that_is_for_neither_a_code_nor_a_recovery_code_has_no_method_and_is_checked_as_the_placeholder_in_its_place(array $body): void
    {
        $attempt = $this->attemptOf(['challenge' => 'lkpc_x'] + $body);

        $this->assertSame([null, self::PLACEHOLDER, ''], [$attempt->method, $attempt->code, $attempt->recoveryCode]);
    }

    public function test_the_challenge_is_cut_at_sixty_four_characters_and_what_is_not_text_is_no_challenge(): void
    {
        $this->assertSame(str_repeat('a', 64), $this->attemptOf(['challenge' => str_repeat('a', 64)])->challenge);
        $this->assertSame(str_repeat('a', 64), $this->attemptOf(['challenge' => str_repeat('a', 65)])->challenge);
        $this->assertSame(64, strlen($this->attemptOf(['challenge' => str_repeat('a', 100000)])->challenge));
        $this->assertSame('', $this->attemptOf(['challenge' => ['lkpc_x']])->challenge);
        $this->assertSame('', $this->attemptOf(['challenge' => 12345])->challenge);
        $this->assertSame('', $this->attemptOf([])->challenge);
    }

    public function test_a_code_is_read_from_its_first_thirty_two_characters_only(): void
    {
        $fits = $this->attemptOf(['code' => str_repeat(' ', 26) . '123456']);
        $cut = $this->attemptOf(['code' => str_repeat(' ', 27) . '123456']);

        $this->assertSame([MfaMethod::Totp, null], [$fits->method, $cut->method]);
    }

    public function test_a_recovery_code_is_read_from_its_first_sixty_four_characters_only(): void
    {
        $fits = $this->attemptOf(['recovery_code' => str_repeat(' ', 53) . 'abcde-23456']);
        $cut = $this->attemptOf(['recovery_code' => str_repeat(' ', 54) . 'abcde-23456']);

        $this->assertSame([MfaMethod::RecoveryCode, null], [$fits->method, $cut->method]);
    }

    public function test_only_the_body_is_read_and_never_the_query_string(): void
    {
        $inBoth = MfaAttempt::fromRequest(Request::create('/?challenge=lkpc_query&code=123456', 'POST', ['challenge' => 'lkpc_body']));
        $inTheQueryOnly = MfaAttempt::fromRequest(Request::create('/?challenge=lkpc_query&code=123456&recovery_code=abcde-23456', 'POST'));

        $this->assertSame(['lkpc_body', null], [$inBoth->challenge, $inBoth->method]);
        $this->assertSame(['', null], [$inTheQueryOnly->challenge, $inTheQueryOnly->method]);
    }

    public function test_the_address_and_the_agent_are_those_of_the_request(): void
    {
        $attempt = MfaAttempt::fromRequest(Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'Phone/1.0']));

        $this->assertSame(['10.1.2.3', 'Phone/1.0'], [$attempt->ip, $attempt->userAgent]);
    }

    /** @param array<string, mixed> $body */
    private function attemptOf(array $body): MfaAttempt
    {
        return MfaAttempt::fromRequest(Request::create('/', 'POST', $body));
    }
}
