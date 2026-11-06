<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SalesChannelsImportStatementCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:import-statement {account : the sales channel account id} {file : a CSV exported from the channel\'s seller portal}';

    protected $description = 'Import a statement a person exported from a channel (for what its API does not give) into the cost ledger; a bad row refuses the whole file, a repeated fact is recorded once';

    public function handle(ManualStatementImporter $importer): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        $account = SalesChannelAccount::find((int) $this->argument('account'));
        $file = (string) $this->argument('file');
        if ($account === null || !is_file($file)) {
            $this->error('The account or the file does not exist.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($account, (string) file_get_contents($file));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(sprintf('%d rows: %d new, %d already imported, %d costs recorded, %d need a person.', $result['rows'], $result['new'], $result['duplicates'], $result['costs_recorded'], $result['needs_review']));

        return self::SUCCESS;
    }
}
