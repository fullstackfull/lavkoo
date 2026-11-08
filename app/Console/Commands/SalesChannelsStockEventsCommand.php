<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\ChannelStockEvent;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\Outbox\StockEventDrain;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use Illuminate\Console\Command;

/**
 * Works the stock outbox from the command line, and says what is waiting.
 *
 * It exists for three reasons, and all three are about not depending on a queue worker:
 *
 *  - a host with no worker running still has every event worked, by the schedule, every minute;
 *  - a host WITH a worker has this as the net beneath it: a nudge that was lost, a worker that was restarted mid-batch, a batch that was put off after a failure;
 *  - `--status` answers "are the workers keeping up?" without a screen, which is the first question when a quantity looks wrong.
 *
 * Draining is not a write of its own: it hands pools to the division and the publisher, which keep every switch and approval. On a host where publishing is off it works out
 * the pools and sends nothing, which is exactly what the scheduled run does.
 */
class SalesChannelsStockEventsCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:stock-events
        {account? : the sales channel account id (default: every account that offers an affected pool)}
        {--status : only say what is waiting, and work nothing}
        {--limit=200 : the most events to work in one pass}
        {--passes=1 : work this many batches in a row (each takes --limit events)}
        {--retry-dead : put the dead events back as waiting, once somebody has dealt with what stopped them}';

    protected $description = 'Work the shared-stock outbox: what changed since the last run is divided again and what lowers a quantity is sent (a WRITE only where the account already allows one)';

    public function handle(StockEventDrain $drain, StockEventOutbox $outbox): int
    {
        if (!config('sales_channels.enabled')) {
            $this->line('Sales channel integrations are switched off (SALES_CHANNELS_ENABLED); nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (!$outbox->on()) {
            $this->line('The stock outbox is switched off (SALES_CHANNELS_STOCK_OUTBOX_ENABLED) or its migration has not run; the scheduled runs do the work.');

            return self::SUCCESS;
        }

        if ($this->option('retry-dead')) {
            $revived = ChannelStockEvent::where('state', ChannelStockEvent::DEAD)->update([
                'state' => ChannelStockEvent::PENDING, 'attempts' => 0, 'available_at' => null, 'failure_code' => null, 'updated_at' => now(),
            ]);
            $this->line('put back as waiting: ' . $revived);
        }

        $this->line('waiting: ' . json_encode($outbox->lag()));
        if ($this->option('status')) {
            return self::SUCCESS;
        }

        $account = $this->argument('account') !== null ? SalesChannelAccount::find((int) $this->argument('account')) : null;
        if ($this->argument('account') !== null && $account === null) {
            $this->error('No such sales channel account.');

            return self::FAILURE;
        }

        $failed = 0;
        for ($pass = 0; $pass < max(1, (int) $this->option('passes')); $pass++) {
            $worked = $drain->drain(max(1, (int) $this->option('limit')), $account);
            $failed += $worked['failed'];
            $this->line('worked: ' . json_encode($worked));
            if ($worked['claimed'] === 0) {
                break;
            }
        }

        // A batch that was put off is not a failure of the command: the events are still there and the next run takes them. It is reported, not made fatal, so the schedule
        // does not fill a log with failures while a channel is having a bad hour.
        if ($failed > 0) {
            $this->warn($failed . ' events were put back for another try.');
        }

        return self::SUCCESS;
    }
}
