<?php

namespace App\Console\Commands;

use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use Illuminate\Console\Command;

/** Lists each company's gateway account, its own webhook address, and WHICH credentials it holds. Never what they are. */
class PaymentGatewayAccountListCommand extends Command
{
    protected $signature = 'gcc:payment-account:list {--gateway= : only the accounts of this gateway}';

    protected $description = "List each company's gateway accounts, their webhook addresses and which credentials they hold";

    public function handle(GatewayAccountRegistry $registry, GatewayAccountDirectory $directory): int
    {
        if (!$registry->isMigrated()) {
            $this->error('The payment_gateway_accounts table is not there yet. Run the migrations first.');

            return self::FAILURE;
        }

        if (!$registry->isEnabled()) {
            $this->warn('Per-company payment accounts are switched off (GCC_PAYMENT_ACCOUNTS), so no payment is taken through an account below.');
        }

        $gateway = $this->option('gateway');
        $rows = $directory->rows(gateway: is_string($gateway) && trim($gateway) !== '' ? trim($gateway) : null);

        if ($rows === []) {
            $this->line('No gateway accounts are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'market', 'company', 'gateway', 'environment', 'active', 'webhook', 'credentials held', 'webhook secret'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['market'],
                $row['company'] ?? '—',
                $row['gateway'],
                $row['environment'],
                $row['active'] ? 'yes' : 'no',
                $row['webhook_url'] ?? '—',
                implode(', ', $row['secrets_held']) ?: '—',
                $row['has_webhook_secret'] ? 'saved' : 'missing',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
