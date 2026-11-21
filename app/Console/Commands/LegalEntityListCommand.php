<?php

namespace App\Console\Commands;

use App\Services\Storefront\LegalEntityRegistry;
use Illuminate\Console\Command;

class LegalEntityListCommand extends Command
{
    protected $signature = 'gcc:legal-entity:list {--json : print the rows as JSON}';

    protected $description = 'Read-only report before legal entities are turned on: for each configured storefront country, the company registered for it. Exits 1 when a storefront that is switched on (other than the default one) has no active company';

    public function handle(LegalEntityRegistry $registry): int
    {
        if (!$registry->isMigrated()) {
            $this->error('The legal_entities table does not exist yet: run the migrations first (they only add a table and two nullable columns to orders).');

            return self::FAILURE;
        }

        $rows = $registry->report();
        $blocked = $registry->isEnabled() && array_filter($rows, static fn (array $row): bool => $row['storefront_active'] && !$row['is_default'] && $row['status'] !== 'active') !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode(['enabled' => $registry->isEnabled(), 'required_to_sell' => $registry->isRequiredToSell(), 'entities' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Legal entities: ' . ($registry->isEnabled() ? 'on' : 'off') . ' (GCC_LEGAL_ENTITIES, and GCC_STOREFRONTS_ENABLED must be on too); a missing company ' . ($registry->isRequiredToSell() ? 'stops a storefront from selling' : 'does not stop a sale') . ' (GCC_LEGAL_ENTITIES_REQUIRED).');

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %-4s %-10s %-9s %s%s',
                $row['country'],
                $row['storefront_active'] ? 'storefront on' : 'storefront off',
                $row['status'],
                $row['legal_name'] ?? '-',
                $row['tax_registration_number'] === null ? '' : ' (tax ' . $row['tax_registration_number'] . ')',
            ));
        }

        $this->line($blocked ? 'Not ready: a storefront that is switched on has no active company. Register it with gcc:legal-entity:upsert, then run this again.' : 'Nothing is missing for a storefront that is switched on.');

        return $blocked ? self::FAILURE : self::SUCCESS;
    }
}
