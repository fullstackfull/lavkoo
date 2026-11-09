<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Enums\OperationalCosts\CostComponent;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooSchema;
use App\Services\Accounting\Odoo\OdooThreeWayReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Builds the three-way reconciliation for a period: the channel's own document, our ledger, and Odoo.
 *
 * It READS all three and writes only its own report rows. No cost is recalculated — `OperationalCosts` is the only engine for
 * that — and **no difference is closed**: the report exists to show one, and a command that resolved differences would be
 * agreeing with whichever figure this code happened to trust.
 */
class OdooReconcileCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'odoo:reconcile
        {--from= : the first day of the period (default: the first day of last month)}
        {--to= : the last day (default: the last day of last month)}
        {--currency=SAR : the currency to reconcile}
        {--channel= : only this channel; omit for every channel together}
        {--component=* : only these cost components; omit for every one the ledger can produce}';

    protected $description = 'Build the three-way reconciliation (channel document / our ledger / Odoo) for a period. Reads only; closes no difference.';

    public function handle(OdooThreeWayReconciliation $reconciliation): int
    {
        if (!config('odoo.enabled')) {
            $this->line('The Odoo integration is switched off (ODOO_ENABLED); nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet() || !OdooSchema::ready()) {
            $this->line('The Odoo migration has not been run yet; nothing to do.');

            return self::SUCCESS;
        }

        $connection = SalesChannelAccount::where('channel', 'odoo')->orderBy('id')->first();
        if ($connection === null) {
            $this->line('No Odoo connection has been saved yet; nothing to do.');

            return self::SUCCESS;
        }

        $from = CarbonImmutable::parse($this->option('from') ?: now()->subMonthNoOverflow()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($this->option('to') ?: now()->subMonthNoOverflow()->endOfMonth()->toDateString());
        $currency = (string) $this->option('currency');
        $channel = $this->option('channel') ?: null;
        $components = $this->components();

        $disagreements = 0;
        foreach ($components as $component) {
            $row = $reconciliation->build($connection, $from, $to, $component, $currency, $channel === null ? null : (string) $channel);
            $missing = $row->missingLegs();
            if ($row->agrees() && $missing === []) {
                continue;
            }

            $disagreements += $row->agrees() ? 0 : 1;
            $this->line(sprintf(
                '%s: source %s · ledger %s · odoo %s%s%s',
                $component,
                $row->source_amount ?? 'not published',
                $row->ledger_amount ?? 'nothing settled',
                $row->odoo_amount ?? 'not read',
                $row->entries_not_posted > 0 ? sprintf(' · %d not posted', $row->entries_not_posted) : '',
                $missing === [] ? '' : ' · no figure from: ' . implode(', ', $missing),
            ));
        }

        $this->line(sprintf('%d components reconciled for %s..%s; %d disagree.', count($components), $from->toDateString(), $to->toDateString(), $disagreements));

        // A disagreement is a thing to look at, not a failure of this command: it did its job by finding one.
        return self::SUCCESS;
    }

    /** @return list<string> */
    private function components(): array
    {
        $asked = array_values(array_filter(array_map('strval', (array) $this->option('component'))));
        if ($asked !== []) {
            return $asked;
        }

        return array_map(static fn (CostComponent $component): string => $component->value, CostComponent::cases());
    }
}
