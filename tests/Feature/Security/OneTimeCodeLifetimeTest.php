<?php

namespace Tests\Feature\Security;

use App\Models\PasswordReset;
use App\Models\PhoneOrEmailVerification;
use App\Services\CustomerPasswordResetService;
use App\Services\Identity\OneTimeCodeLifetime;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * A2-05: a reset or login code older than its lifetime is refused.
 *
 * Both tables that hold a code have carried an `expires_at` column since 2023 and nothing has ever written or read it, so a code lived
 * until the next one for the same identity replaced it or the row was spent: the code texted to a customer last March still signed them in,
 * and the reset token mailed to an address they have since lost still changed their password. The attempt counter closed none of that -- it
 * is cleared the moment a new code is asked for, and the old row's code was good either way.
 *
 * `gcc.identity.one_time_code_lifetime` is off and off is today's platform, which the first three tests measure -- the first of them on the
 * SHAPE of the narrowed query and not only on its rows, because a clause that is added and then happens not to bite is a different answer
 * from a clause that was never added at all.
 *
 * The two figures are separate because the two tables hold two different things: `phone_or_email_verifications` holds only codes a person
 * TYPES, `password_resets` also holds the long token the seller reset MAILS as a link. `test_the_two_tables_take_their_own_lifetimes` is
 * what pins that, with one age that is live in one table and dead in the other.
 *
 * The delivery man's own path is deliberately untouched: it already refuses a code more than two minutes old and already uses `expires_at`
 * for the window between a verified code and the new password, so narrowing it here would have made two records of one fact.
 */
class OneTimeCodeLifetimeTest extends CustomerPasswordResetTestCase
{
    private const CODE = '654321';

    private const CODE_MINUTES = 10;

    private const RESET_MINUTES = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.identity.one_time_code_lifetime.code_minutes' => self::CODE_MINUTES]);
        config(['gcc.identity.one_time_code_lifetime.reset_minutes' => self::RESET_MINUTES]);
    }

    private function switchOn(): void
    {
        config(['gcc.identity.one_time_code_lifetime.enabled' => true]);
    }

    private function lifetime(): OneTimeCodeLifetime
    {
        return app(OneTimeCodeLifetime::class);
    }

    /** A reset proof as the server wrote it $minutesAgo. */
    private function resetProofIssued(string $identity, int $minutesAgo, string $code = self::CODE): void
    {
        DB::table('password_resets')->insert([
            'identity' => $identity, 'token' => $code, 'user_type' => 'customer',
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /** A login code as a resend wrote it $minutesAgo. */
    private function loginCodeIssued(string $identity, int $minutesAgo, string $code = self::CODE): void
    {
        DB::table('phone_or_email_verifications')->insert([
            'phone_or_email' => $identity, 'token' => $code,
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function enterResetCode(string $identity, string|int $code): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/verify-recover-password', [
            'identity' => base64_encode($identity), 'type' => base64_encode('phone_verification'), 'token' => $code,
        ]);
    }

    private function submitNewPassword(string $identity, string $token): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/reset-password', [
            'identity' => base64_encode($identity), 'reset_token' => $token, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    private function verifyPhoneCode(string $phone, string|int $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/verify-phone', ['phone' => $phone, 'token' => $code]);
    }

    // ---- the switch is off: today's platform, to the shape of the query -------------------------------------------

    public function test_with_the_switch_off_no_clause_is_added_to_either_lookup(): void
    {
        foreach ([PasswordReset::query(), PhoneOrEmailVerification::query()] as $plain) {
            $narrowed = $plain->clone()->withinLifetime();

            $this->assertSame($plain->toSql(), $narrowed->toSql(), 'the lookup must be the one it is today, not one with a clause that happens not to bite');
            $this->assertSame($plain->getBindings(), $narrowed->getBindings());
        }

        $this->assertFalse($this->lifetime()->enabled(), 'the switch ships off');
        $this->assertMatchesRegularExpression('/^GCC_ONE_TIME_CODE_LIFETIME=false$/m', (string) file_get_contents(base_path('.env.example')), 'and the example file each CI job copies ships it off too');
    }

    public function test_with_the_switch_off_a_code_issued_a_year_ago_still_resets_the_password(): void
    {
        $customerId = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->resetProofIssued(self::OWNER_PHONE, minutesAgo: 60 * 24 * 365);

        $this->assertNotNull(
            app(CustomerPasswordResetService::class)->findCustomerHoldingCode(identity: self::OWNER_PHONE, code: self::CODE),
            'the proof a year old is still redeemable, which is what this platform does today',
        );
        $this->submitNewPassword(self::OWNER_PHONE, self::CODE);
        $this->assertTookPassword($customerId);
    }

    public function test_with_the_switch_off_a_login_code_issued_a_year_ago_still_verifies_the_phone(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_phone_verified' => 0]);
        $this->loginCodeIssued(self::OWNER_PHONE, minutesAgo: 60 * 24 * 365);

        $this->verifyPhoneCode(self::OWNER_PHONE, self::CODE)->assertOk()->assertJsonPath('status', true);
        $this->assertSame(1, (int) DB::table('users')->where('phone', self::OWNER_PHONE)->value('is_phone_verified'));
    }

    // ---- the switch is on ----------------------------------------------------------------------------------------

    public function test_a_reset_proof_older_than_its_lifetime_is_refused_and_the_password_stands(): void
    {
        $this->switchOn();
        $customerId = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->resetProofIssued(self::OWNER_PHONE, minutesAgo: self::RESET_MINUTES + 1);

        $this->submitNewPassword(self::OWNER_PHONE, self::CODE)->assertRedirect('/customer/auth/recover-password');
        $this->assertKeepsPassword($customerId);
    }

    public function test_a_reset_proof_within_its_lifetime_still_resets_the_password(): void
    {
        $this->switchOn();
        $customerId = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->resetProofIssued(self::OWNER_PHONE, minutesAgo: self::RESET_MINUTES - 1);

        $this->submitNewPassword(self::OWNER_PHONE, self::CODE);
        $this->assertTookPassword($customerId);
    }

    /**
     * The refusal must read as the refusal of a code that is not the right one -- the same redirect and the same session error -- so that
     * neither app needs a release and nothing tells a guesser that the number they typed was once a real code.
     */
    public function test_a_stale_code_is_refused_in_the_same_shape_as_a_wrong_one(): void
    {
        $this->switchOn();
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->resetProofIssued(self::OWNER_PHONE, minutesAgo: self::RESET_MINUTES + 1);

        $stale = $this->enterResetCode(self::OWNER_PHONE, self::CODE);
        $wrong = $this->enterResetCode(self::OWNER_PHONE, '000000');

        $this->assertSame($wrong->headers->get('Location'), $stale->headers->get('Location'));
        $this->assertSame($wrong->getStatusCode(), $stale->getStatusCode());
    }

    public function test_a_stale_login_code_is_refused_with_the_body_an_unknown_code_is_refused_with(): void
    {
        $this->switchOn();
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_phone_verified' => 0]);
        $this->loginCodeIssued(self::OWNER_PHONE, minutesAgo: self::CODE_MINUTES + 1);

        $stale = $this->verifyPhoneCode(self::OWNER_PHONE, self::CODE);
        $stale->assertStatus(403)->assertJsonPath('errors.0.code', 'token');
        $this->assertSame(0, (int) DB::table('users')->where('phone', self::OWNER_PHONE)->value('is_phone_verified'));
        $this->assertSame(
            $this->verifyPhoneCode(self::OWNER_PHONE, '000000')->json(),
            $stale->json(),
            'a code that has run out is refused as a code that was never issued, body for body',
        );
    }

    /**
     * The row must still be FOUND by the attempt counter, which looks it up by identity alone. Narrowing that too would have turned a stale
     * code into an oracle with no limit: every guess against it would have been free.
     */
    public function test_a_stale_code_still_counts_against_the_attempt_limit(): void
    {
        $this->switchOn();
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->loginCodeIssued(self::OWNER_PHONE, minutesAgo: self::CODE_MINUTES + 1);

        $this->verifyPhoneCode(self::OWNER_PHONE, self::CODE)->assertStatus(403);

        $this->assertSame(1, (int) DB::table('phone_or_email_verifications')->where('phone_or_email', self::OWNER_PHONE)->value('otp_hit_count'));
    }

    public function test_the_two_tables_take_their_own_lifetimes(): void
    {
        $this->switchOn();
        $halfAnHour = 30;

        $this->resetProofIssued(self::OWNER_PHONE, minutesAgo: $halfAnHour);
        $this->loginCodeIssued(self::OWNER_PHONE, minutesAgo: $halfAnHour);

        $this->assertNotNull(
            PasswordReset::query()->where('token', self::CODE)->withinLifetime()->first(),
            'half an hour is inside the hour a mailed reset link is given',
        );
        $this->assertNull(
            PhoneOrEmailVerification::query()->where('token', self::CODE)->withinLifetime()->first(),
            'half an hour is well past the ten minutes a typed login code is given',
        );
    }

    /** No issuing path writes one, and a code whose age nothing records cannot be shown to be live. */
    public function test_a_code_with_no_issue_time_is_refused_when_the_switch_is_on_and_kept_when_it_is_off(): void
    {
        DB::table('password_resets')->insert(['identity' => self::OWNER_PHONE, 'token' => self::CODE, 'user_type' => 'customer', 'created_at' => null, 'updated_at' => null]);

        $this->assertNotNull(PasswordReset::query()->where('token', self::CODE)->withinLifetime()->first(), 'nothing is narrowed while the switch is off');

        $this->switchOn();
        $this->assertNull(PasswordReset::query()->where('token', self::CODE)->withinLifetime()->first());
        $this->assertTrue($this->lifetime()->hasRunOut((object) ['created_at' => null], OneTimeCodeLifetime::RESET));
    }

    /** A lifetime of zero would refuse a code in the instant it was issued, which is a broken login and not a security property. */
    public function test_a_lifetime_below_one_minute_is_read_as_one_minute(): void
    {
        config(['gcc.identity.one_time_code_lifetime.code_minutes' => 0]);
        config(['gcc.identity.one_time_code_lifetime.reset_minutes' => -5]);

        $this->assertSame(1, $this->lifetime()->minutes(OneTimeCodeLifetime::CODE));
        $this->assertSame(1, $this->lifetime()->minutes(OneTimeCodeLifetime::RESET));
    }

    /**
     * The boundary: a code at EXACTLY its lifetime has run out, a code a second inside it has not. The clock is frozen, because without
     * that the row's moment and the cutoff are computed microseconds apart and `<` and `<=` agree -- the test passed under both operators
     * and proved nothing.
     */
    public function test_the_boundary_of_the_lifetime_is_closed_against_the_code(): void
    {
        $this->switchOn();
        $this->travelTo(now()->startOfSecond());

        $this->assertTrue($this->lifetime()->hasRunOut((object) ['created_at' => now()->subMinutes(self::CODE_MINUTES)], OneTimeCodeLifetime::CODE));
        $this->assertFalse($this->lifetime()->hasRunOut((object) ['created_at' => now()->subMinutes(self::CODE_MINUTES)->addSecond()], OneTimeCodeLifetime::CODE));
    }
}
