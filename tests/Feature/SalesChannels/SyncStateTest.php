<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Sync\ChannelSyncRunner;
use App\Services\SalesChannels\Sync\SyncLease;
use App\Services\SalesChannels\Sync\SyncStateStore;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class SyncStateTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private SyncStateStore $states;

    private ChannelSyncRunner $runner;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->states = app(SyncStateStore::class);
        $this->runner = app(ChannelSyncRunner::class);
        $this->account = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'T', 'enabled' => true]);
    }

    public function test_only_one_run_holds_a_resource_at_a_time(): void
    {
        $first = $this->states->acquire($this->account->id, 'orders');
        $second = $this->states->acquire($this->account->id, 'orders');
        $other = $this->states->acquire($this->account->id, 'listings');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNotNull($other, 'A different resource of the same account is independent.');
    }

    public function test_a_crashed_run_loses_its_lease_by_itself(): void
    {
        $this->states->acquire($this->account->id, 'orders');
        $this->travel(config('sales_channels.sync.lease_minutes') + 1)->minutes();

        $this->assertNotNull($this->states->acquire($this->account->id, 'orders'));
    }

    public function test_the_watermark_moves_only_when_a_run_completes_and_the_next_run_reads_a_little_before_it(): void
    {
        $lease = $this->states->acquire($this->account->id, 'orders');
        $this->states->succeed($lease, CarbonImmutable::parse('2026-11-10 12:00:00'), ['orders' => 4]);

        $next = $this->states->acquire($this->account->id, 'orders');
        $from = $this->states->readFrom($next);

        $this->assertSame('2026-11-10 11:50:00', $from->format('Y-m-d H:i:s'), 'Ten minutes of overlap, so nothing changed meanwhile is missed.');

        $this->states->fail($next, 'boom');
        $this->travel(2)->hours();
        $again = $this->states->acquire($this->account->id, 'orders');
        $this->assertSame('2026-11-10 12:00:00', $again->watermark->format('Y-m-d H:i:s'), 'A failed run does not move it.');
    }

    public function test_a_first_run_reads_from_the_start_of_the_backfill_window(): void
    {
        $lease = $this->states->acquire($this->account->id, 'orders');

        $from = $this->states->readFrom($lease);

        $this->assertTrue($from->between(now()->subDays(31), now()->subDays(29)));
    }

    public function test_an_unfinished_run_leaves_its_cursor_and_the_next_one_resumes_there(): void
    {
        $lease = $this->states->acquire($this->account->id, 'listings');
        $this->states->pause($lease, ['page' => 7], ['listings' => 700]);

        $next = $this->states->acquire($this->account->id, 'listings');

        $this->assertSame(['page' => 7], $next->cursor);
    }

    public function test_failures_back_off_longer_each_time_and_a_success_clears_them(): void
    {
        foreach ([1, 2, 3] as $count) {
            $lease = $this->states->acquire($this->account->id, 'orders');
            $this->assertNotNull($lease, "attempt {$count} should be allowed once the back-off has passed");
            $this->states->fail($lease, 'channel down');
            $state = $this->states->state($this->account->id, 'orders');
            $this->assertSame($count, $state->consecutive_failures);
            $this->assertNull($this->states->acquire($this->account->id, 'orders'), 'No new run during the back-off.');
            $this->travel($count * (int) config('sales_channels.sync.backoff_minutes_per_failure') + 1)->minutes();
        }

        $lease = $this->states->acquire($this->account->id, 'orders');
        $this->states->succeed($lease, null, []);
        $this->assertSame(0, $this->states->state($this->account->id, 'orders')->consecutive_failures);
    }

    public function test_a_resource_is_due_when_it_never_ran_or_its_interval_has_passed(): void
    {
        $this->assertTrue($this->states->isDue($this->account->id, 'orders', 5));

        $lease = $this->states->acquire($this->account->id, 'orders');
        $this->states->succeed($lease, null, []);
        $this->assertFalse($this->states->isDue($this->account->id, 'orders', 5));

        $this->travel(6)->minutes();
        $this->assertTrue($this->states->isDue($this->account->id, 'orders', 5));
    }

    public function test_a_five_minute_resource_is_due_at_the_next_five_minute_tick_even_though_its_run_took_a_few_seconds(): void
    {
        $lease = $this->states->acquire($this->account->id, 'orders');
        $this->travel(8)->seconds();
        $this->states->succeed($lease, null, []);

        $this->travel(4)->minutes();
        $this->travel(58)->seconds();
        $this->assertTrue($this->states->isDue($this->account->id, 'orders', 5), 'Run at :00 and finished at :08, it is due at :05, not :10.');

        $this->travelTo($this->states->state($this->account->id, 'orders')->last_success_at->copy()->addMinutes(3));
        $this->assertFalse($this->states->isDue($this->account->id, 'orders', 5));
    }

    public function test_a_sweep_that_stopped_halfway_goes_on_at_the_next_tick_not_after_a_whole_interval(): void
    {
        $lease = $this->states->acquire($this->account->id, 'listings');
        $this->states->pause($lease, ['token' => 'T-2'], []);

        $this->assertTrue($this->states->isDue($this->account->id, 'listings', 360), 'A six-hour resource with work left does not wait six hours.');

        $second = $this->states->acquire($this->account->id, 'listings');
        $this->states->backOff($second, 600, 'rate limited');
        $this->assertFalse($this->states->isDue($this->account->id, 'listings', 360), 'But it still waits out a back-off.');
    }

    public function test_the_runner_reports_a_switch_that_is_off_as_a_skip_and_not_an_error(): void
    {
        $result = $this->runner->run($this->account, 'orders', function (): array {
            throw new ChannelUnavailableException(ChannelUnavailableException::CAPABILITY_OFF, 'Order import is off.');
        });

        $this->assertSame(ChannelSyncRunner::SKIPPED, $result['result']);
        $state = $this->states->state($this->account->id, 'orders');
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertNull($state->backoff_until);
    }

    public function test_a_rate_limit_is_a_pause_with_the_channels_wait_not_a_failure(): void
    {
        $result = $this->runner->run($this->account, 'orders', function (): array {
            throw new ChannelRateLimitedException(90);
        });

        $this->assertSame(ChannelSyncRunner::RATE_LIMITED, $result['result']);
        $state = $this->states->state($this->account->id, 'orders');
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertTrue($state->backoff_until->isFuture());
    }

    public function test_a_failure_is_reported_as_a_failure_with_the_reason_and_never_as_success(): void
    {
        $result = $this->runner->run($this->account, 'orders', function (): array {
            throw new RuntimeException('Gateway exploded');
        });

        $this->assertSame(ChannelSyncRunner::FAILED, $result['result']);
        $this->assertStringContainsString('Gateway exploded', (string) $result['detail']);
        $this->assertNull($this->account->fresh()->last_synced_at);
    }

    public function test_a_lost_authorisation_marks_the_account_so_a_person_reauthorises(): void
    {
        $this->runner->run($this->account, 'orders', function (): array {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'The seller withdrew authorisation.');
        });

        $this->assertSame('auth_expired', $this->account->fresh()->connection_status);
    }

    public function test_a_success_records_the_time_and_the_stats(): void
    {
        $result = $this->runner->run($this->account, 'orders', fn (SyncLease $lease): array => ['watermark' => CarbonImmutable::parse('2026-11-10 12:00:00'), 'stats' => ['orders' => 3]]);

        $this->assertSame(ChannelSyncRunner::OK, $result['result']);
        $this->assertNotNull($this->account->fresh()->last_synced_at);
        $this->assertSame(['orders' => 3], $this->states->state($this->account->id, 'orders')->stats);
    }

    public function test_a_second_run_while_the_first_is_working_is_told_it_is_busy(): void
    {
        $inner = null;
        $this->runner->run($this->account, 'orders', function () use (&$inner): array {
            $inner = $this->runner->run($this->account, 'orders', fn (): array => []);

            return [];
        });

        $this->assertSame(ChannelSyncRunner::BUSY, $inner['result']);
    }
}
