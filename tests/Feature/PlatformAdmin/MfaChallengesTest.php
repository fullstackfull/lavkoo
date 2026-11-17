<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminMfaChallenge;
use App\Services\PlatformAdmin\PlatformAdminMfaChallenges;
use Illuminate\Support\Facades\DB;

/**
 * The store of challenges on its own: what it gives, what it counts and in what order it refuses.
 */
class MfaChallengesTest extends MfaTestCase
{
    public function test_a_challenge_is_issued_with_what_it_needs_and_found_by_its_plain_value(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $store = $this->store();

        $issued = $store->issue(admin: $this->adminRow($admin), grant: $this->grantRow($admin), deviceName: 'Pixel 9');

        $this->assertMatchesRegularExpression('/^lkpc_[A-Za-z0-9]{48}$/', $issued->plain);
        $this->assertSame('2026-11-17 10:05:00', $issued->expiresAt->format('Y-m-d H:i:s'));
        $found = $store->find(candidate: $issued->plain);
        $this->assertNotNull($found);
        $this->assertSame([$admin['id'], $admin['grant_id'], 'Pixel 9', 0], [$found->admin_id, $found->grant_id, $found->device_name, $found->attempts]);
        $this->assertNull($store->find(candidate: $issued->plain . 'x'));
        $this->assertNull($store->find(candidate: ''));
        $this->assertNull($store->find(candidate: hash('sha256', $issued->plain)), 'the stored hash is not the challenge');
    }

    public function test_a_try_cannot_be_made_for_a_challenge_that_is_unknown_consumed_over_or_used_up_and_the_reasons_come_in_that_order(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $store = $this->store();
        $challenge = $this->challenge($admin);

        $this->assertNull($store->whyNoTryCanBeMade(challenge: $challenge));
        $this->assertSame('unknown_challenge', $store->whyNoTryCanBeMade(challenge: null));

        $this->update($challenge, ['attempts' => 4]);
        $this->assertNull($store->whyNoTryCanBeMade(challenge: $this->fresh($challenge)), 'the last try is still a try');
        $this->update($challenge, ['attempts' => 5]);
        $this->assertSame('attempts_exhausted', $store->whyNoTryCanBeMade(challenge: $this->fresh($challenge)));
        $this->update($challenge, ['attempts' => 6]);
        $this->assertSame('attempts_exhausted', $store->whyNoTryCanBeMade(challenge: $this->fresh($challenge)));

        $this->update($challenge, ['expires_at' => now()]);
        $this->assertSame('expired_challenge', $store->whyNoTryCanBeMade(challenge: $this->fresh($challenge)), 'expired comes before used up, and its last moment is over');

        $this->update($challenge, ['consumed_at' => now()]);
        $this->assertSame('consumed_challenge', $store->whyNoTryCanBeMade(challenge: $this->fresh($challenge)), 'consumed comes before everything but unknown');
    }

    public function test_the_number_of_tries_a_challenge_allows_is_the_configured_one(): void
    {
        $store = $this->store();
        $challenge = $this->challenge($this->enrolledAdmin());
        config(['gcc.platform_admin.mfa.max_challenge_attempts' => 2]);

        $this->assertSame(1, $store->countTry(challenge: $challenge));
        $this->assertFalse($store->allowsNoMoreTries(triesMade: 1));
        $this->assertSame(2, $store->countTry(challenge: $challenge));
        $this->assertTrue($store->allowsNoMoreTries(triesMade: 2));
        $this->assertNull($store->countTry(challenge: $challenge), 'a third try is not counted, because there is none');
        $this->assertSame(2, (int) $this->fresh($challenge)->attempts);
    }

    public function test_a_try_is_counted_only_while_the_challenge_is_open(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $store = $this->store();
        $admin = $this->enrolledAdmin();

        foreach ([['consumed_at' => now()], ['expires_at' => now()], ['expires_at' => now()->subMinute()], ['attempts' => 5]] as $state) {
            $challenge = $this->challenge($admin);
            $this->update($challenge, $state);

            $this->assertNull($store->countTry(challenge: $challenge), json_encode($state));
            $this->assertSame($state['attempts'] ?? 0, (int) $this->fresh($challenge)->attempts, 'and it counts nothing: ' . json_encode($state));
        }

        $open = $this->challenge($admin);
        $this->assertSame(1, $store->countTry(challenge: $open));
        $this->update($open, ['expires_at' => now()->addSecond()]);
        $this->assertSame(2, $store->countTry(challenge: $open), 'a second before the last moment it is open');
    }

    public function test_counting_a_try_touches_only_that_challenge(): void
    {
        $store = $this->store();
        $admin = $this->enrolledAdmin();
        $first = $this->challenge($admin);
        $second = $this->challenge($admin);

        $store->countTry(challenge: $first);

        $this->assertSame([1, 0], [(int) $this->fresh($first)->attempts, (int) $this->fresh($second)->attempts]);
    }

    public function test_a_challenge_is_consumed_once_and_keeps_the_time_it_was_consumed(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $store = $this->store();
        $challenge = $this->challenge($this->enrolledAdmin());

        $this->assertTrue($store->consume(challenge: $challenge));
        $this->travelTo('2026-11-17 10:01:00');
        $this->assertFalse($store->consume(challenge: $challenge), 'it was this call, or it was not');

        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_mfa_challenges')->value('consumed_at'));
    }

    public function test_ending_all_of_an_admins_challenges_ends_only_the_open_ones_of_that_admin(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $store = $this->store();
        $admin = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();
        $open = $this->challenge($admin);
        $consumed = $this->challenge($admin);
        $this->update($consumed, ['consumed_at' => now()->subHour()]);
        $theirs = $this->challenge($other);

        $this->assertSame(1, $store->endAllFor(adminId: $admin['id']));

        $this->assertSame('2026-11-17 10:00:00', $this->fresh($open)->consumed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17 09:00:00', $this->fresh($consumed)->consumed_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->fresh($theirs)->consumed_at);
        $this->assertSame(0, $store->endAllFor(adminId: $admin['id']));
    }

    private function store(): PlatformAdminMfaChallenges
    {
        return $this->app->make(PlatformAdminMfaChallenges::class);
    }

    private function adminRow(array $admin): Admin
    {
        return Admin::query()->findOrFail($admin['id']);
    }

    private function grantRow(array $admin): PlatformAdminGrant
    {
        return PlatformAdminGrant::query()->findOrFail($admin['grant_id']);
    }

    private function challenge(array $admin): PlatformAdminMfaChallenge
    {
        $issued = $this->store()->issue(admin: $this->adminRow($admin), grant: $this->grantRow($admin), deviceName: 'Test phone');

        return $this->store()->find(candidate: $issued->plain);
    }

    private function fresh(PlatformAdminMfaChallenge $challenge): PlatformAdminMfaChallenge
    {
        return PlatformAdminMfaChallenge::query()->findOrFail($challenge->getKey());
    }

    /** @param array<string, mixed> $values */
    private function update(PlatformAdminMfaChallenge $challenge, array $values): void
    {
        DB::table('platform_admin_mfa_challenges')->where('id', $challenge->getKey())->update($values);
    }
}
