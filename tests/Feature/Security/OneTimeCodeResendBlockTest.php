<?php

namespace Tests\Feature\Security;

use App\Models\PhoneOrEmailVerification;
use App\Services\Identity\OneTimeCodeBlock;
use Illuminate\Support\Facades\DB;

/**
 * A2-05: a resend cannot lift a live block on a one-time code.
 *
 * The verify paths count the wrong codes and block a number once the operator's `maximum_otp_hit` is reached. The RESEND paths never asked:
 * `resend_otp_check_phone` and `resend_otp_check_email` set `otp_hit_count = 0`, `is_temp_blocked = 0` and `temp_block_time = null` for
 * ANYONE who knew the phone number or the e-mail address. So whoever was guessing codes asked for a new one -- which needs no code, no
 * session and nothing but the number -- and the block was gone. The gate counted to the maximum for ever and never closed.
 *
 * What is changed is only that: a resend asked for while a block is LIVE is refused, with the message the verify path already gives for the
 * same reason. A block that has run out is cleared exactly as it was, so a customer who waited out their block is not kept out by this.
 *
 * The last test is a characterisation and is NOT endorsed: `temporary_block_time` is labelled minutes in the admin settings and every
 * comparison in the application reads it as seconds, so a block an operator set to five minutes lasts five seconds. It is pinned as it
 * stands rather than quietly changed, because making every block sixty times longer is a decision about the live Saudi login.
 */
class OneTimeCodeResendBlockTest extends CustomerAuthTestCase
{
    private const PHONE = '0500000005';

    private const EMAIL = 'layla.victim@example.test';

    private const RESEND_PHONE = '/api/v1/auth/resend-otp-check-phone';

    private const RESEND_EMAIL = '/api/v1/auth/resend-otp-check-email';

    private const TEMPORARY_TOKEN = 'temporary-token-of-the-customer';

    /** Minutes by the name of the setting, seconds by every comparison in the code. */
    private const BLOCK_LENGTH = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCustomer(['temporary_token' => self::TEMPORARY_TOKEN]);
        DB::table('business_settings')->insert([
            ['type' => 'otp_resend_time', 'value' => '0'],
            ['type' => 'temporary_block_time', 'value' => (string) self::BLOCK_LENGTH],
            ['type' => 'maximum_otp_hit', 'value' => '5'],
        ]);
        clearWebConfigCacheKeys();
    }

    /** A number that is blocked right now, as the verify path leaves it after too many wrong codes. */
    private function aBlockedCode(string $identity, int $secondsAgo = 0): int
    {
        return DB::table('phone_or_email_verifications')->insertGetId([
            'phone_or_email' => $identity,
            'token' => '1234',
            'otp_hit_count' => 5,
            'is_temp_blocked' => 1,
            'temp_block_time' => now()->subSeconds($secondsAgo),
            'created_at' => now()->subSeconds($secondsAgo),
            'updated_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    /** @return array{otp_hit_count: int, is_temp_blocked: int, token: string} the state of the block, as the gate reads it */
    private function blockOf(int $id): array
    {
        $row = PhoneOrEmailVerification::query()->findOrFail($id);

        return [
            'otp_hit_count' => (int) $row->otp_hit_count,
            'is_temp_blocked' => (int) $row->is_temp_blocked,
            'token' => (string) $row->token,
        ];
    }

    public function test_a_resend_of_a_phone_code_is_refused_while_the_block_is_live(): void
    {
        $id = $this->aBlockedCode(self::PHONE);

        $this->postJson(self::RESEND_PHONE, ['temporary_token' => self::TEMPORARY_TOKEN, 'phone' => self::PHONE])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.message', fn (string $message): bool => str_contains($message, translate('please_try_again_after')));

        $this->assertSame(
            ['otp_hit_count' => 5, 'is_temp_blocked' => 1, 'token' => '1234'],
            $this->blockOf($id),
            'the block, the count and the code are all left as they were: a resend is not a way out',
        );
    }

    public function test_a_resend_of_an_email_code_is_refused_while_the_block_is_live(): void
    {
        $id = $this->aBlockedCode(self::EMAIL);

        $this->postJson(self::RESEND_EMAIL, ['temporary_token' => self::TEMPORARY_TOKEN, 'email' => self::EMAIL])
            ->assertStatus(403);

        $this->assertSame(['otp_hit_count' => 5, 'is_temp_blocked' => 1, 'token' => '1234'], $this->blockOf($id));
    }

    /** A customer who waited out their block is not kept out: the resend works and clears it, exactly as it did before. */
    public function test_a_resend_after_the_block_has_run_out_is_allowed_and_clears_it(): void
    {
        $id = $this->aBlockedCode(self::PHONE, secondsAgo: self::BLOCK_LENGTH + 1);

        $this->postJson(self::RESEND_PHONE, ['temporary_token' => self::TEMPORARY_TOKEN, 'phone' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('token', 'active');

        $after = $this->blockOf($id);
        $this->assertSame(0, $after['otp_hit_count']);
        $this->assertSame(0, $after['is_temp_blocked']);
        // A new code WAS issued; its value cannot be compared, because this test case runs in dev mode and a dev-mode phone code is the
        // fixed 1234 (OneTimeCodesByAppModeTest pins that). What moved is the row's own clock, which is what the resend cooldown reads.
        $this->assertTrue(
            PhoneOrEmailVerification::query()->findOrFail($id)->created_at->greaterThan(now()->subSecond()),
            'the code row was written again',
        );
    }

    /** And a number that was never blocked resends as it always has. */
    public function test_a_resend_with_no_block_at_all_is_allowed(): void
    {
        $id = DB::table('phone_or_email_verifications')->insertGetId([
            'phone_or_email' => self::PHONE, 'token' => '1234', 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);

        $this->postJson(self::RESEND_PHONE, ['temporary_token' => self::TEMPORARY_TOKEN, 'phone' => self::PHONE])->assertOk();

        $this->assertSame(0, $this->blockOf($id)['is_temp_blocked']);
    }

    /** A number with no code row at all is a first request, and is answered as one. */
    public function test_a_resend_for_a_number_with_no_code_yet_is_allowed(): void
    {
        $this->postJson(self::RESEND_PHONE, ['temporary_token' => self::TEMPORARY_TOKEN, 'phone' => self::PHONE])->assertOk();

        $this->assertSame(1, DB::table('phone_or_email_verifications')->where('phone_or_email', self::PHONE)->count());
    }

    public function test_the_gate_reads_a_row_with_no_block_as_not_blocked(): void
    {
        $gate = app(OneTimeCodeBlock::class);

        $this->assertFalse($gate->isBlocked(null));
        $this->assertSame(0, $gate->remainingSeconds(PhoneOrEmailVerification::query()->findOrFail(DB::table('phone_or_email_verifications')->insertGetId([
            'phone_or_email' => self::PHONE, 'token' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]))));
    }

    /**
     * NOT ENDORSED. `temporary_block_time` is labelled minutes where an operator sets it and is compared against a count of SECONDS
     * everywhere in the application, so a block set to five minutes lasts five seconds. Pinned here so that changing it is a deliberate
     * change with a failing test to update, and not a surprise.
     */
    public function test_current_behaviour_a_block_lasts_as_many_seconds_as_the_setting_names_minutes(): void
    {
        $gate = app(OneTimeCodeBlock::class);
        $justBlocked = PhoneOrEmailVerification::query()->findOrFail($this->aBlockedCode(self::PHONE));
        $blockedSixSecondsAgo = PhoneOrEmailVerification::query()->findOrFail($this->aBlockedCode(self::EMAIL, secondsAgo: self::BLOCK_LENGTH + 1));

        $this->assertSame(self::BLOCK_LENGTH, $gate->remainingSeconds($justBlocked), 'five SECONDS, from a setting that says five minutes');
        $this->assertSame(0, $gate->remainingSeconds($blockedSixSecondsAgo), 'and six seconds later it is over');
    }
}
