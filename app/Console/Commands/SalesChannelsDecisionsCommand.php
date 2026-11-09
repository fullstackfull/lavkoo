<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\ChannelPackageSplit;
use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Returns\ChannelReturnDecisions;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use Illuminate\Console\Command;

/**
 * Sends what a person decided: the customer returns they answered, and the packages they asked to be cut.
 *
 * **Both pipelines are useless without this.** A decision is recorded by a screen and sent by a run; with no run, a return a
 * pharmacist approved sits in a table for ever and a customer is never refunded. That is why one command drives both rather
 * than each waiting for its own: they are the same shape — a person decided, a row was written, something has to carry it —
 * and a host that forgot to schedule one would have forgotten the other.
 *
 * What they do NOT share is what happens when an answer goes missing, and the command says so rather than hiding it:
 *
 *  - a **return decision** whose outcome is unknown waits for a person, because Trendyol publishes no way to ask what became
 *    of one claim-item decision. Nothing retries it, ever.
 *  - a **split** whose outcome is unknown is ASKED about: the package's own status is the answer, so the run reads the order
 *    back until it knows or gives up on asking. Nothing re-sends it either way.
 *
 * `--status` answers "is anything stuck?" without a screen, which is the first question when a customer says they were never
 * refunded. Neither pipeline sends anything on an account whose switches or approvals do not allow it; both simply report that.
 */
class SalesChannelsDecisionsCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:decisions
        {account? : the sales channel account id (default: every enabled account)}
        {--status : only say what is waiting, and send nothing}
        {--returns : only the customer returns}
        {--splits : only the package splits}
        {--limit=20 : the most rows of each kind to work in one pass}';

    protected $description = 'Send the return decisions a person made and work the package splits they asked for (a WRITE only where the account already allows one)';

    public function handle(ChannelReturnDecisions $returns, ChannelPackageSplits $splits): int
    {
        if (!config('sales_channels.enabled')) {
            $this->line('Sales channel integrations are switched off (SALES_CHANNELS_ENABLED); nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        // Neither flag means both, which is what the schedule wants.
        $doReturns = !$this->option('splits') || $this->option('returns');
        $doSplits = !$this->option('returns') || $this->option('splits');

        $this->line('waiting: ' . json_encode($this->waiting()));
        if ($this->option('status')) {
            $this->reportWhatIsStuck();

            return self::SUCCESS;
        }

        $accounts = $this->accounts();
        if ($accounts === null) {
            // A named account that does not exist is a mistake in the command line, not an empty queue: it fails, so a cron
            // entry with a stale id is noticed instead of reporting success for ever while nothing is sent.
            return self::FAILURE;
        }
        if ($accounts === []) {
            $this->line('No enabled account to work.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        foreach ($accounts as $account) {
            if ($doReturns && ChannelReturnDecisions::ready()) {
                $this->line("account {$account->id} returns: " . json_encode($returns->send($account, $limit)));
            }
            if ($doSplits && ChannelPackageSplits::ready()) {
                $this->line("account {$account->id} splits: " . json_encode($splits->run($account, $limit)));
            }
        }

        $this->reportWhatIsStuck();

        return self::SUCCESS;
    }

    /**
     * The accounts to work, or null when the one that was named does not exist -- which is a different thing from none being
     * enabled, and has a different exit code.
     *
     * @return list<SalesChannelAccount>|null
     */
    private function accounts(): ?array
    {
        if ($this->argument('account') !== null) {
            $account = SalesChannelAccount::find((int) $this->argument('account'));
            if ($account === null) {
                $this->error('No such sales channel account.');

                return null;
            }

            return [$account];
        }

        return SalesChannelAccount::where('enabled', true)->orderBy('id')->get()->all();
    }

    /** @return array<string, int> */
    private function waiting(): array
    {
        $waiting = [];
        if (ChannelReturnDecisions::ready()) {
            $waiting['returns_pending'] = ChannelReturnDecision::where('state', ChannelReturnDecision::PENDING)->count();
            $waiting['returns_need_a_person'] = ChannelReturnDecision::whereIn('state', [ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::NEEDS_REVIEW])->count();
        }
        if (ChannelPackageSplits::ready()) {
            $waiting['splits_pending'] = ChannelPackageSplit::where('state', ChannelPackageSplit::PENDING)->count();
            $waiting['splits_awaiting_proof'] = ChannelPackageSplit::where('state', ChannelPackageSplit::MAY_HAVE_SENT)->count();
            $waiting['splits_need_a_person'] = ChannelPackageSplit::where('state', ChannelPackageSplit::NEEDS_REVIEW)->count();
        }

        return $waiting;
    }

    /**
     * Says out loud what no run will ever pick up again.
     *
     * A fenced return decision and a split whose proof never came are both somebody's job now, and a command that printed
     * only its own totals would let them sit unnoticed between one scheduled run and the next.
     */
    private function reportWhatIsStuck(): void
    {
        $forAPerson = ChannelReturnDecisions::ready()
            ? ChannelReturnDecision::whereIn('state', [ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::NEEDS_REVIEW])->count()
            : 0;
        if ($forAPerson > 0) {
            $this->warn($forAPerson . ' return decision(s) may have reached the channel and cannot be asked about: a person has to check them at the channel. Nothing will retry them.');
        }

        $splitsForAPerson = ChannelPackageSplits::ready() ? ChannelPackageSplit::where('state', ChannelPackageSplit::NEEDS_REVIEW)->count() : 0;
        if ($splitsForAPerson > 0) {
            $this->warn($splitsForAPerson . ' package split(s) were sent and never proved: a person has to look at the order. Nothing will send them again.');
        }
    }
}
