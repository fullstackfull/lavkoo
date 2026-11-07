<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectionTester;
use Illuminate\Console\Command;

class SalesChannelsTestConnectionCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:test-connection {account : the sales channel account id}';

    protected $description = 'Prove an account\'s credentials with harmless reads and show which permissions it has (creates and changes nothing at the channel)';

    public function handle(ChannelConnectionTester $tester): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        $account = SalesChannelAccount::find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $result = $tester->test($account);
        $this->line($result->message);
        $this->line('Granted: ' . ($result->granted === [] ? '-' : implode(', ', $result->granted)));
        $this->line('Missing: ' . ($result->missing === [] ? '-' : implode(', ', $result->missing)));

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }
}
