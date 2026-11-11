<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Amazon\AmazonReportRunner;
use App\Services\SalesChannels\Amazon\AmazonReportTypes;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Amazon's reports: find the ones it schedules by itself, ask for the ones it lets us ask for, and read whatever is ready.
 *
 * Three verbs because Amazon has three different relationships with a report, not because three felt tidy:
 *
 *  - `--discover` asks `getReports` what Amazon holds. **The only way a settlement report is ever reached**, since its own page
 *    says settlement reports "cannot be requested or scheduled", and also how a request whose reply was lost finds its report
 *    again instead of making a second one.
 *  - `--ask=<type>` requests a report of a type Amazon allows to be requested. It refuses any other type by name.
 *  - with neither, it works the due rows: polls what Amazon is still making and reads what is ready.
 *
 * It is safe to run on a schedule with nothing to do: with no account, no due row and nothing to discover it makes no call at
 * all. `createReport` is rated at one request a minute, so the asking is deliberately a thing a person or a daily schedule does
 * and never a loop.
 */
class SalesChannelsAmazonReportsCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:amazon-reports
        {--account= : one Amazon account id; every enabled Amazon account by default}
        {--discover : ask Amazon which reports it already holds, which is the only way a settlement report is found}
        {--ask= : request a report of this type (only a type Amazon allows to be requested)}
        {--from= : the window start for --ask (Y-m-d); the first of last month by default}
        {--to= : the window end for --ask (Y-m-d); the last of last month by default}
        {--since= : how far back --discover looks (Y-m-d); 30 days by default, and Amazon keeps reports 90}
        {--limit=10 : how many due rows to work}';

    protected $description = 'Find, request and read Amazon report documents; a settlement report is never requested, and a request whose reply was lost is found rather than repeated';

    public function handle(AmazonReportRunner $runner): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (!AmazonReportRunner::ready()) {
            $this->warn('The report table is not there yet: run the migration 2026_11_17_100000_create_amazon_report_runs_table.');

            return self::SUCCESS;
        }

        $accounts = $this->accounts();
        if ($accounts === []) {
            $this->line('No enabled Amazon account.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($accounts as $account) {
            try {
                $this->workOne($runner, $account);
            } catch (ChannelUnavailableException | ChannelPermissionDenied $refused) {
                // A switch, a capability or an authorisation. Said and moved past: one account being off is not a reason to
                // leave the others unread.
                $this->warn($account->name . ': ' . $refused->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @throws ChannelUnavailableException|ChannelPermissionDenied */
    private function workOne(AmazonReportRunner $runner, SalesChannelAccount $account): void
    {
        $asked = trim((string) $this->option('ask'));
        if ($asked !== '') {
            [$from, $to] = $this->window();
            $row = $runner->ask($account, $asked, $from, $to);
            $this->line(sprintf(
                '%s: %s %s -> %s%s',
                $account->name,
                $asked,
                $from->toDateString() . '..' . $to->toDateString(),
                $row->state,
                $row->failure_code === null ? ' (' . (string) $row->amazon_report_id . ')' : ' ' . $row->failure_code,
            ));
        }

        if ($this->option('discover')) {
            $since = CarbonImmutable::parse((string) ($this->option('since') ?: now()->subDays(30)->toDateString()));
            // Every type we know how to read: a settlement because it is the only route to one, and the requestable types
            // because this is also how a lost reply finds its report.
            $found = $runner->discover($account, array_keys(AmazonReportTypes::all()), $since);
            $this->line(sprintf(
                '%s: %d new report%s, %d recovered after a lost reply%s',
                $account->name,
                $found['found'],
                $found['found'] === 1 ? '' : 's',
                $found['recovered'],
                $found['problem'] === null ? '' : ' -- ' . $found['problem'],
            ));
        }

        $stats = $runner->work($account, (int) $this->option('limit'));
        $this->line(sprintf(
            '%s: polled %d, read %d, empty %d, failed %d, unresolved %d.',
            $account->name,
            $stats['polled'],
            $stats['read'],
            $stats['empty'],
            $stats['failed'],
            $stats['unknown'],
        ));

        $waiting = AmazonReportRun::where('account_id', $account->id)->where('state', AmazonReportRun::UNKNOWN)->whereNull('amazon_report_id')->count();
        if ($waiting > 0) {
            // Said plainly, because these are the rows a machine will never resolve by itself: a request may have reached
            // Amazon under a number nobody saw, and only `--discover` can find it.
            $this->warn(sprintf('%s: %d request%s may have reached Amazon with the reply lost. They are never requested again -- run with --discover to find them.', $account->name, $waiting, $waiting === 1 ? '' : 's'));
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if ($from !== null && $from !== '') {
            $start = CarbonImmutable::parse((string) $from);

            return [$start, CarbonImmutable::parse((string) ($to ?: $start->endOfMonth()->toDateString()))];
        }

        // Last month, whole: the window a settlement or a listings snapshot is usually wanted for, and one nobody has to type.
        $lastMonth = CarbonImmutable::now()->subMonthNoOverflow();

        return [$lastMonth->startOfMonth(), $lastMonth->endOfMonth()];
    }

    /** @return list<SalesChannelAccount> */
    private function accounts(): array
    {
        $one = $this->option('account');

        return SalesChannelAccount::where('channel', 'amazon')
            ->where('enabled', true)
            ->when($one !== null && $one !== '', fn ($query) => $query->whereKey((int) $one))
            ->orderBy('id')
            ->get()
            ->all();
    }
}
