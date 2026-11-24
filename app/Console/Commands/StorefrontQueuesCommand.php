<?php

namespace App\Console\Commands;

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
 * It only reads: pausing is `queue:pause`, resuming is `queue:resume`, and both are Laravel's own.
 */
class StorefrontQueuesCommand extends Command
{
    protected $signature = 'gcc:storefront-queues {--json : print the report as JSON}';

    protected $description = "Each market's queue: its name, whether it is paused, what is waiting on it and the command to work it";

    public function handle(QueueFactory $queues): int
    {
        $connection = (string) config('queue.default');
        $report = [
            'per_market_queues' => StorefrontQueues::isEnabled(),
            'connection' => $connection,
            'default_storefront' => StorefrontResolver::fromConfig()->defaultCountry(),
            'markets' => $this->markets(queues: $queues, connection: $connection),
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
            ['market', 'queue', 'state', 'waiting', 'failed', 'work it with'],
            array_map(static fn (array $market): array => [
                $market['country'],
                $market['queue'],
                $market['paused'] ? 'PAUSED' : 'open',
                $market['waiting'] ?? '—',
                $market['failed'] ?? '—',
                sprintf('php artisan queue:work %s --queue=%s', $connection, $market['queue']),
            ], $report['markets']),
        );

        $this->line('Pause one market: php artisan queue:pause ' . $report['markets'][0]['queue'] . '   (queue:resume starts it again)');

        return self::SUCCESS;
    }
}
