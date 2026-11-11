<?php

namespace App\Console\Commands;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only: does this database hold a channel fee net filed under the service reference it used to use?
 *
 * ## Why the check exists
 *
 * Until 2026-11-11 `ChannelFeeImporter` filed a channel's fee net under the EMPTY service reference whenever the fee's
 * currency matched the order's, which is the same slot the contract-priced estimate uses. It now files every net under
 * `channel-net:<CUR>`, so the estimate survives until a settlement is proven.
 *
 * A row written the old way is not migrated by that change. It stays effective, a new fee for the same order is written in
 * the new slot, and both are counted: measured on a copy, two 12.00 SAR nets gave an actual cost of 24.00, with the
 * estimate beside them already superseded by the legacy row. So this must be answered BEFORE the module's migrations are
 * run against a database, and the owner's instruction is that a count above zero stops the rollout rather than triggering a
 * migration written on a guess.
 *
 * ## Why it is a command and not a SQL query
 *
 * The first version of this check was SQL in the rehearsal document, rebuilding the hash with
 * `SHA2(CONCAT_WS('|', 'slot', seller_id, …, ''), 256)`. That is wrong in a way that under-reports: `slotKey()` builds its
 * string with `implode`, which renders a NULL field as an EMPTY SEGMENT, while SQL's `CONCAT_WS` omits a NULL argument
 * altogether. One nullable column with a null in it and the two strings differ, the hashes differ, and the check says zero
 * where the hazard is real. A safety check that can silently miss is worse than none.
 *
 * So this asks `CostDraft::slotKey()` itself. It cannot drift from the implementation, because it IS the implementation.
 *
 * ## What it does and does not do
 *
 * It reads. It writes nothing, migrates nothing and changes no key. It exits non-zero when it finds anything -- and also
 * when it could not read the database at all, because an unreachable database is not an empty one and "I could not look"
 * must stop a rollout exactly as a positive finding does.
 */
class CostsCheckLegacyCommissionSlotsCommand extends Command
{
    protected $signature = 'costs:check-legacy-commission-slots {--limit=20 : how many examples to print} {--json : print the report as JSON}';

    protected $description = 'Read-only: finds channel fee nets filed under the pre-2026-11-11 service reference, which would be counted twice. Writes nothing';

    public function handle(): int
    {
        try {
            $hasTable = Schema::hasTable('operational_cost_entries');
        } catch (Throwable $exception) {
            // **An unreachable database is not an empty one.** Without this the check died with a stack trace, and a
            // rollout script reading an exit code would have learned nothing about whether the hazard is present. The
            // answer "I could not look" has to be its own answer, and it has to stop the rollout exactly as a positive
            // finding does.
            $this->report([
                'table' => null,
                'legacy_rows' => null,
                'verdict' => 'could_not_look',
                'message' => 'The database could not be read, so nothing is known about legacy commission rows: ' . $exception->getMessage(),
                'examples' => [],
            ]);

            return self::FAILURE;
        }

        if (!$hasTable) {
            $this->report([
                'table' => false,
                'legacy_rows' => 0,
                'verdict' => 'not_required',
                'message' => 'There is no operational_cost_entries table here, so no commission row of any kind exists and nothing needs migrating.',
                'examples' => [],
            ]);

            return self::SUCCESS;
        }

        $examples = [];
        $legacy = 0;
        $checked = 0;

        OperationalCostEntry::query()
            ->where('source', 'channel_api')
            ->where('is_effective', true)
            ->whereIn('state', [CostState::Actual->value, CostState::Reconciled->value])
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (OperationalCostEntry $entry) use (&$legacy, &$examples, &$checked): void {
                $checked++;
                if ((string) $entry->slot_key !== $this->slotKeyFor($entry, '')) {
                    return;
                }
                $legacy++;
                if (count($examples) < max(1, (int) $this->option('limit'))) {
                    $examples[] = [
                        'id' => (int) $entry->id,
                        'component' => (string) $entry->cost_component,
                        'currency' => (string) $entry->currency,
                        'amount' => (string) $entry->amount,
                        'order_mapping_id' => $entry->external_order_mapping_id,
                        'period_date' => $entry->period_date?->toDateString(),
                        'would_move_to' => 'channel-net:' . (string) $entry->currency,
                    ];
                }
            });

        $this->report([
            'table' => true,
            'channel_api_rows_checked' => $checked,
            'legacy_rows' => $legacy,
            'verdict' => $legacy === 0 ? 'no_risk_in_this_data' : 'stop_and_plan_a_data_repair',
            'message' => $legacy === 0
                ? 'No channel fee net is filed under the old service reference, so this database carries none of the hazard and no migration is needed for it.'
                : "{$legacy} channel fee net(s) are filed under the old service reference. Each would be counted a second time once a new fee arrives for the same order. Do not run the module's migrations against this database until a data-repair plan has been agreed.",
            'examples' => $examples,
        ]);

        return $legacy === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The slot an entry would have with this service reference, computed by the ledger's own draft. */
    private function slotKeyFor(OperationalCostEntry $entry, string $serviceRef): string
    {
        return (new CostDraft(
            subjectType: (string) $entry->subject_type,
            subjectId: (int) $entry->subject_id,
            component: CostComponent::from((string) $entry->cost_component),
            state: CostState::Actual,
            amount: Money::zero(),
            currency: (string) $entry->currency,
            periodDate: CarbonImmutable::now(),
            source: 'check',
            identity: 'check',
            serviceRef: $serviceRef,
            sellerId: (int) $entry->seller_id,
        ))->slotKey();
    }

    /** @param array<string, mixed> $report */
    private function report(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line($report['message']);
        $this->line('verdict: ' . $report['verdict']);
        foreach ($report['examples'] as $example) {
            $this->line(sprintf(
                '  entry %d  %s  %s %s  -> %s',
                $example['id'],
                $example['component'],
                $example['amount'],
                $example['currency'],
                $example['would_move_to'],
            ));
        }
    }
}
