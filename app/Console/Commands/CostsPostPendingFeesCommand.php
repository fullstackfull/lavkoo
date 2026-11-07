<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Console\Commands\Concerns\SkipsWhileOperationalCostsAreOff;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Illuminate\Console\Command;
use Throwable;

class CostsPostPendingFeesCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;
    use SkipsWhileOperationalCostsAreOff;

    protected $signature = 'costs:post-pending-fees {account? : the sales channel account id (default: every account that has postings waiting)}';

    protected $description = 'Post the marketplace fees and adjustments the channel statements reported while the cost module was switched off (or that the database refused once); each is posted exactly once';

    public function handle(ChannelFeeImporter $fees): int
    {
        if ($this->operationalCostsAreOff() || $this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $only = $this->argument('account') === null ? null : SalesChannelAccount::find((int) $this->argument('account'));
        if ($this->argument('account') !== null && $only === null) {
            $this->error('The account does not exist.');

            return self::FAILURE;
        }

        $posted = 0;
        $failed = 0;
        $accounts = $only !== null ? collect([$only]) : SalesChannelAccount::whereIn('id', $fees->accountsWithPendingPostings())->orderBy('id')->get();
        foreach ($accounts as $account) {
            try {
                $posted += $fees->postPending($account);
            } catch (Throwable $exception) {
                // One account whose postings cannot be written must not keep the others from being posted; what was not written stays waiting.
                report($exception);
                $failed++;
            }
        }
        $this->line(sprintf('%d cost entries posted from the stored statements; %d postings still waiting%s', $posted, $fees->pendingPostings($only), $failed > 0 ? "; {$failed} accounts could not be posted." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
