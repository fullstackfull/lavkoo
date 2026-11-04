<?php

namespace App\Services\SellerAutomation;

use App\Models\Product;
use App\Services\Platform\Policy;
use App\Models\ProductPriceChange;
use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerAutomationRun;
use App\Services\AuditLogger;
use App\Services\Marketplace\PriceChangeRecorder;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runs a seller's rules, and stops them when they misbehave.
 *
 * Three properties matter more than anything the engine actually does.
 *
 * A preview and a run take the same path. `preview()` and `run()` share the trigger, the action's
 * own `preview()`, and the cap — the only difference is whether the write happens. A dry run that
 * used different code would be a demonstration rather than a preview, and a seller who trusted it
 * would be right to feel misled when the real run did something else.
 *
 * A run that would touch too much does nothing. Not "does as much as the cap allows": a rule that
 * suddenly matches the whole catalogue has almost certainly been mis-written or is reading a number
 * that changed meaning, and applying the first fifty of those changes is the outcome that is hardest
 * to undo. It stops, records what it would have touched, and asks for a person.
 *
 * A suspended rule stays suspended until somebody clears it. Automatic recovery would make the
 * breaker pointless — the rule would trip, wait, and trip again, forever, while the shop drifted.
 */
class AutomationEngine
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly SellerPermissionService $permissions,
    ) {
    }

    /**
     * What this rule would do right now, without doing any of it.
     *
     * @return array{matched: int, capped: bool, subjects: array<int, array>}
     */
    public function preview(SellerAutomationRule $rule, int $limit = 25): array
    {
        $trigger = $this->registry->triggerFor($rule);
        $action = $this->registry->actionFor($rule);

        if (!$trigger || !$action) {
            return ['matched' => 0, 'capped' => false, 'subjects' => []];
        }

        // One past the cap, so "more than you allow" is distinguishable from "exactly your cap".
        $matched = $trigger->match(
            $rule->seller_id,
            $rule->trigger_settings ?? [],
            $rule->max_actions_per_run + 1,
            $rule->scope ?? [],
        );
        $capped = $matched->count() > $rule->max_actions_per_run;

        $subjects = $matched->take($limit)->map(function (object $subject) use ($action, $rule) {
            $planned = $action->preview($subject, $rule->action_settings ?? []);

            return [
                'subject_id' => (int) $subject->id,
                'label' => $planned['label'] ?? null,
                'will_apply' => (bool) $planned['ok'],
                'reason' => $planned['reason'] ?? null,
                'before' => $planned['before'] ?? null,
                'after' => $planned['after'] ?? null,
            ];
        })->values()->all();

        return ['matched' => $matched->count(), 'capped' => $capped, 'subjects' => $subjects];
    }

    /**
     * Run one rule and write down everything it did.
     *
     * Returns the run row rather than a bare count: "matched nothing" and "did not run" are
     * different answers, and only one of them is a problem.
     */
    public function run(SellerAutomationRule $rule): SellerAutomationRun
    {
        $startedAt = now();

        $principal = $this->permissions->principalForSeller(
            sellerId: $rule->seller_id,
            staffId: $rule->created_by_staff_id,
            apiKeyId: $rule->created_by_api_key_id,
        );

        $trigger = $this->registry->triggerFor($rule);
        $action = $this->registry->actionFor($rule);

        if (!$trigger || !$action) {
            return $this->fail($rule, $startedAt, 'automation_failed_unknown_trigger_or_action', suspend: true);
        }

        // Re-checked here, not only when the rule was written. A shop suspended or a permission
        // revoked since then has to take effect on the work, not merely on the next login.
        if (!$principal) {
            return $this->fail($rule, $startedAt, 'automation_failed_seller_no_longer_active', suspend: true);
        }

        if (!$principal->can($action->permission())) {
            return $this->fail($rule, $startedAt, 'automation_failed_permission_revoked', suspend: true);
        }

        try {
            $matched = $trigger->match(
                $rule->seller_id,
                $rule->trigger_settings ?? [],
                $rule->max_actions_per_run + 1,
                $rule->scope ?? [],
            );
        } catch (Throwable $exception) {
            return $this->fail($rule, $startedAt, 'automation_failed_trigger_error', detail: $exception->getMessage());
        }

        if ($matched->count() > $rule->max_actions_per_run) {
            return $this->capped($rule, $startedAt, $matched->count());
        }

        return $this->apply($rule, $principal, $action, $matched, $startedAt);
    }

    /**
     * Run every rule that is due.
     *
     * @return array<int, SellerAutomationRun>
     */
    public function runDue(int|string|null $sellerId = null, ?int $limit = null): array
    {
        if (!Schema::hasTable('seller_automation_rules')) {
            return [];
        }

        // Past this many active rules some sellers' automation simply never runs, and nothing said
        // so. The number is the marketplace's to raise as it grows rather than a constant here.
        $limit ??= app(Policy::class)->int('limit_automation_sweep');

        $rules = SellerAutomationRule::where('status', SellerAutomationRule::STATUS_ACTIVE)
            ->when($sellerId !== null, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByRaw('last_run_at IS NULL DESC')
            ->orderBy('last_run_at')
            ->limit($limit)
            ->get();

        $runs = [];

        foreach ($rules as $rule) {
            if (!$rule->isDue()) {
                continue;
            }

            $runs[] = $this->run($rule);
        }

        return $runs;
    }

    /**
     * Put back one thing a rule did.
     *
     * Only the columns the action itself declares, and only from the `before` it recorded at the
     * time. Anything else would make the trail a general-purpose write endpoint aimed at whatever
     * row id somebody puts in it.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function revert(SellerAutomationAction $record, SellerPrincipal $principal): array
    {
        if (!$record->isRevertible()) {
            return ['ok' => false, 'reason' => 'automation_reason_not_revertible'];
        }

        $action = $this->registry->action($record->action);

        if (!$action) {
            return ['ok' => false, 'reason' => 'automation_reason_unknown_action'];
        }

        if (!$principal->can($action->permission())) {
            return ['ok' => false, 'reason' => 'automation_reason_permission'];
        }

        if ($record->subject_type !== SellerAutomationAction::SUBJECT_PRODUCT) {
            return ['ok' => false, 'reason' => 'automation_reason_not_revertible'];
        }

        $product = Product::withoutGlobalScope('translate')
            ->where('id', $record->subject_id)
            ->where(['added_by' => 'seller', 'user_id' => $principal->sellerId()])
            ->first();

        if (!$product) {
            return ['ok' => false, 'reason' => 'automation_reason_subject_gone'];
        }

        $restore = array_intersect_key($record->before ?? [], array_flip($action->revertibleColumns()));

        if ($restore === []) {
            return ['ok' => false, 'reason' => 'automation_reason_not_revertible'];
        }

        PriceChangeRecorder::attributeTo(
            source: ProductPriceChange::SOURCE_SELLER_UI,
            reason: 'automation_undo',
            work: fn () => $product->forceFill($restore)->save(),
        );

        $record->forceFill(['reverted_at' => now()])->save();

        $this->audit->record(
            action: 'seller.automation_action_reverted',
            subject: ['type' => 'product', 'id' => $product->id],
            before: $record->after,
            after: $restore,
            // The shop's id belongs on every line, or the seller's own history does not show it.
            context: ['seller_id' => $principal->sellerId(), 'rule_id' => $record->rule_id, 'actor' => $principal->actorLabel()],
        );

        return ['ok' => true];
    }

    /**
     * @param  Collection<int, object>  $matched
     */
    private function apply(
        SellerAutomationRule $rule,
        SellerPrincipal $principal,
        AutomationAction $action,
        Collection $matched,
        \DateTimeInterface $startedAt,
    ): SellerAutomationRun {
        $run = SellerAutomationRun::create([
            'rule_id' => $rule->id,
            'seller_id' => $rule->seller_id,
            'outcome' => SellerAutomationRun::OUTCOME_NO_MATCH,
            'matched_count' => $matched->count(),
            // Written as zeros rather than left to the column defaults: a run interrupted halfway
            // should read as "nothing applied yet", not as a row with no answer in it.
            'applied_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'started_at' => $startedAt,
        ]);

        $subjectType = $this->registry->triggerFor($rule)?->subjectType() ?? SellerAutomationAction::SUBJECT_PRODUCT;
        $applied = 0;
        $skipped = 0;
        $failed = 0;

        // Everything this run changes is attributed to the rule rather than to whoever happens to
        // be signed in, so a price a seller did not type can be traced back to the rule that moved
        // it — by name, not by a shrug.
        PriceChangeRecorder::attributeTo(
            source: ProductPriceChange::SOURCE_AUTOMATION,
            reason: $rule->name,
            work: function () use ($matched, $action, $rule, $principal, $run, $subjectType, &$applied, &$skipped, &$failed): void {
                foreach ($matched as $subject) {
                    try {
                        $result = $action->apply($subject, $rule->action_settings ?? [], $principal);
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->recordAction($run, $rule, $subject, $subjectType, [
                            'ok' => false,
                            'reason' => 'automation_reason_failed',
                        ], SellerAutomationAction::STATUS_FAILED, $exception->getMessage());

                        continue;
                    }

                    if ($result['ok']) {
                        $applied++;
                        $this->recordAction($run, $rule, $subject, $subjectType, $result, SellerAutomationAction::STATUS_APPLIED);
                    } else {
                        $skipped++;
                        $this->recordAction($run, $rule, $subject, $subjectType, $result, SellerAutomationAction::STATUS_SKIPPED);
                    }
                }
            },
        );

        $outcome = match (true) {
            $failed > 0 && $applied === 0 => SellerAutomationRun::OUTCOME_FAILED,
            $applied > 0 => SellerAutomationRun::OUTCOME_APPLIED,
            default => SellerAutomationRun::OUTCOME_NO_MATCH,
        };

        $run->forceFill([
            'outcome' => $outcome,
            'applied_count' => $applied,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'finished_at' => now(),
        ])->save();

        $this->closeRun($rule, $applied, $outcome === SellerAutomationRun::OUTCOME_FAILED);

        if ($applied > 0) {
            $this->audit->record(
                action: 'seller.automation_rule_applied',
                subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
                context: [
                    'seller_id' => $rule->seller_id,
                    'run_id' => $run->id,
                    'applied' => $applied,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ],
            );
        }

        return $run;
    }

    private function recordAction(
        SellerAutomationRun $run,
        SellerAutomationRule $rule,
        object $subject,
        string $subjectType,
        array $result,
        string $status,
        ?string $detail = null,
    ): void {
        SellerAutomationAction::create([
            'run_id' => $run->id,
            'rule_id' => $rule->id,
            'seller_id' => $rule->seller_id,
            'subject_type' => $subjectType,
            'subject_id' => (int) $subject->id,
            // The stored name rather than the accessor's: the accessor answers in the reader's
            // locale and lazily loads a translation per row, which in a sweep is one query per
            // product for a label nobody is reading in that locale anyway.
            'subject_label' => $result['label'] ?? (method_exists($subject, 'getRawOriginal') ? $subject->getRawOriginal('name') : null),
            'action' => $rule->action,
            'status' => $status,
            'reason' => $detail ? mb_substr($detail, 0, 191) : ($result['reason'] ?? null),
            'before' => $result['before'] ?? null,
            'after' => $result['after'] ?? null,
        ]);
    }

    /** A run that stopped because the rule was about to touch more than the seller allowed. */
    private function capped(SellerAutomationRule $rule, \DateTimeInterface $startedAt, int $matched): SellerAutomationRun
    {
        $run = SellerAutomationRun::create([
            'rule_id' => $rule->id,
            'seller_id' => $rule->seller_id,
            'outcome' => SellerAutomationRun::OUTCOME_CAPPED,
            'matched_count' => $matched,
            'applied_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'message' => 'automation_stopped_more_matches_than_allowed',
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        // Counted as a run — it evaluated, it just refused to act — before the breaker is tripped.
        $this->closeRun($rule, applied: 0, failed: false);
        $this->suspend($rule, 'automation_suspended_too_many_matches');

        return $run;
    }

    private function fail(
        SellerAutomationRule $rule,
        \DateTimeInterface $startedAt,
        string $message,
        bool $suspend = false,
        ?string $detail = null,
    ): SellerAutomationRun {
        $run = SellerAutomationRun::create([
            'rule_id' => $rule->id,
            'seller_id' => $rule->seller_id,
            'outcome' => SellerAutomationRun::OUTCOME_FAILED,
            'matched_count' => 0,
            'applied_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 1,
            'message' => $detail ? $message . ': ' . mb_substr($detail, 0, 300) : $message,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $this->closeRun($rule, applied: 0, failed: true);

        if ($suspend) {
            $this->suspend($rule, $message);
        }

        return $run;
    }

    /**
     * Update the rule's counters after a run, and trip the breaker on a run of failures.
     *
     * The failure count is consecutive: one clean run clears it. A rule that fails once a week for
     * a month is a different thing from a rule that has failed three times in a row, and only the
     * second is broken enough to stop.
     */
    private function closeRun(SellerAutomationRule $rule, int $applied, bool $failed): void
    {
        $failures = $failed ? $rule->consecutive_failures + 1 : 0;

        $rule->forceFill([
            'last_run_at' => now(),
            'last_fired_at' => $applied > 0 ? now() : $rule->last_fired_at,
            'run_count' => $rule->run_count + 1,
            'applied_count' => $rule->applied_count + $applied,
            'consecutive_failures' => $failures,
        ])->save();

        if ($failures >= SellerAutomationRule::FAILURE_LIMIT) {
            $this->suspend($rule, 'automation_suspended_repeated_failures');
        }
    }

    private function suspend(SellerAutomationRule $rule, string $reason): void
    {
        if ($rule->isSuspended()) {
            return;
        }

        $rule->forceFill([
            'status' => SellerAutomationRule::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_PLATFORM,
        ])->save();

        $this->audit->record(
            action: 'seller.automation_rule_suspended',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            context: ['seller_id' => $rule->seller_id, 'reason' => $reason],
        );
    }
}
