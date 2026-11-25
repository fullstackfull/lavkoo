<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontQueues;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each market's queue is called, whether it is being worked, and how much is waiting on it (A1-14).
 *
 * An operator who gives each market its own queue needs a worker for each of them, and the one thing that cannot be seen from outside is
 * whether they started one: a market whose queue nobody works does not fail, it simply never sends. This prints the queue of every market,
 * whether it is paused, how many jobs are waiting and how many have failed, and the command to work it — so "is Kuwait being worked?" has an
 * answer before a customer asks it.
 *
 * **Two different pauses, side by side on purpose.** Pausing a market's QUEUE (`queue:pause storefront-kw`) stops its work being reserved.
 * Pausing the MARKET ({@see StorefrontLifecycle::pause()}) stops it SELLING and deliberately leaves its earlier obligations running, which
 * is what GCC-12 requires: a stopped market still refunds, still answers a gateway callback and still tells a customer where a parcel is.
 * Neither implies the other, so a market can be stopped while its queue is open -- which looks exactly like a stop that has not taken
 * effect -- and a queue can be paused while the market still sells, which looks exactly like a market that is closed. An operator who
 * cannot see both at once will read one as the other, so the report prints both and names which is which.
 *
 * It only reads: pausing a queue is `queue:pause`, resuming it is `queue:resume`, both Laravel's own, and stopping a market selling is the
 * storefront lifecycle.
 */
class StorefrontQueuesCommand extends Command
{
    protected $signature = 'gcc:storefront-queues {--json : print the report as JSON}';

    protected $description = "Each market's queue: its name, whether it is paused, what is waiting on it and the command to work it";

    public function handle(QueueFactory $queues, StorefrontLifecycle $lifecycle): int
    {
        $connection = (string) config('queue.default');
        $report = [
            'per_market_queues' => StorefrontQueues::isEnabled(),
            'connection' => $connection,
            'default_storefront' => StorefrontResolver::fromConfig()->defaultCountry(),
            'markets' => $this->markets(queues: $queues, connection: $connection),
            // A dimension of its own rather than a column of the rows above, because a market's lifecycle is not a property of its queue:
            // it exists for a market that has no queue of its own, and it means a different thing. Keeping it apart also leaves the shape
            // of a market row exactly as it was, which is what StorefrontQueuesTest reads.
            'selling' => $this->selling(lifecycle: $lifecycle),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->print(report: $report, connection: $connection);
    }

    /** @return list<array<string, mixed>> */
    private function markets(QueueFactory $queues, string $connection): array
    {
        $rows = [];

        foreach (StorefrontQueues::all() as $country => $queue) {
            $rows[] = [
                'country' => $country,
                'queue' => $queue,
                'paused' => $queues->isPaused($connection, $queue),
                'waiting' => $this->countOn(table: 'jobs', queue: $queue),
                'failed' => $this->countOn(table: 'failed_jobs', queue: $queue),
            ];
        }

        return $rows;
    }

    /**
     * What each configured market's lifecycle says: whether it is selling, and the mode it is stopped in when it is paused. Read for every
     * configured market and not only the ones with a queue, because the default storefront has no queue of its own and can still be stopped.
     *
     * @return array<string, array{state: string, mode: string|null}>
     */
    private function selling(StorefrontLifecycle $lifecycle): array
    {
        $states = [];

        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            $status = $lifecycle->statusOf($country);

            $states[$country] = ['state' => (string) $status['state'], 'mode' => $status['mode']];
        }

        return $states;
    }

    /**
     * How many rows of that queue the table holds, or null when there is no answer to give: a queue kept somewhere other than the database,
     * a table that is not there, or a database this process cannot reach. The names and the paused state are the point of the report and
     * need no database at all, so an unreachable one leaves a column unknown instead of leaving the operator with nothing.
     */
    private function countOn(string $table, string $queue): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            return Schema::hasTable($table) ? DB::table($table)->where('queue', $queue)->count() : null;
        } catch (QueryException) {
            return null;
        }
    }

    /** @param array<string, mixed> $report */
    private function print(array $report, string $connection): int
    {
        if (!$report['per_market_queues']) {
            $this->line('Per-market queues are off: every job and queued listener waits on the queue it waits on today.');
            $this->line('Switch them on with GCC_STOREFRONT_QUEUES=true, once a worker is ready for each market below.');
        }

        $this->line(sprintf(
            'The %s storefront keeps this connection\'s own queue, so a worker that is already running goes on working its jobs.',
            $report['default_storefront'],
        ));

        if ($report['markets'] === []) {
            $this->line('No other market has a queue of its own.');

            return self::SUCCESS;
        }

        $this->table(
            ['market', 'queue', 'queue state', 'selling', 'waiting', 'failed', 'work it with'],
            array_map(fn (array $market): array => [
                $market['country'],
                $market['queue'],
                $market['paused'] ? 'PAUSED' : 'open',
                $this->sellingLabel(selling: $report['selling'], country: (string) $market['country']),
                $market['waiting'] ?? '—',
                $market['failed'] ?? '—',
                sprintf('php artisan queue:work %s --queue=%s', $connection, $market['queue']),
            ], $report['markets']),
        );

        $this->line('Pause one market\'s QUEUE: php artisan queue:pause ' . $report['markets'][0]['queue'] . '   (queue:resume starts it again)');
        $this->line('Stopping a market SELLING is the storefront column, and a different thing: a stopped market still refunds, still');
        $this->line('answers a gateway callback and still tells a customer where a parcel is, so its queue stays open on purpose.');

        return self::SUCCESS;
    }

    /** @param array<string, array{state: string, mode: string|null}> $selling */
    private function sellingLabel(array $selling, string $country): string
    {
        $status = $selling[$country] ?? null;

        if ($status === null) {
            return '—';
        }

        return $status['state'] . ($status['mode'] === null ? '' : ' (' . $status['mode'] . ')');
    }
}
