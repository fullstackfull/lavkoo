<?php

namespace App\Services\SellerAutomation;

use App\Models\SellerAutomationRule;
use App\Services\AuditLogger;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writing, changing and switching a rule on or off.
 *
 * Settings are validated against the trigger and the action the rule actually names, rather than
 * against a fixed list. A rule stored with settings its own trigger does not understand is a rule
 * that will fail at three in the morning with nobody watching, so it never gets stored.
 */
class SellerAutomationRuleService
{
    /** A rule may not be allowed to touch the whole catalogue however carefully it is written. */
    public const MAX_ACTIONS_PER_RUN = 500;

    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function create(array $input, SellerPrincipal $principal): SellerAutomationRule
    {
        $data = $this->validate($input, $principal);

        $rule = SellerAutomationRule::create([
            'seller_id' => $principal->sellerId(),
            'created_by_staff_id' => $principal->staffId(),
            // The key, when it was a key. Without this the rule re-resolves as the owner and
            // outlives the credential that wrote it.
            'created_by_api_key_id' => $principal->apiKeyId(),
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'action' => $data['action'],
            'trigger_settings' => $data['trigger_settings'],
            'action_settings' => $data['action_settings'],
            'scope' => $data['scope'],
            'status' => $data['status'],
            'max_actions_per_run' => $data['max_actions_per_run'],
            'cooldown_minutes' => $data['cooldown_minutes'],
        ]);

        // Read back, so the counters the database defaulted come out as zero rather than null. A
        // client that has to treat "never run" as null on creation and zero everywhere else will
        // eventually get it wrong in one of the two places.
        $rule->refresh();

        $this->audit->record(
            action: 'seller.automation_rule_created',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            after: $rule->only(['name', 'trigger', 'action', 'trigger_settings', 'action_settings', 'scope', 'status']),
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        return $rule;
    }

    /**
     * @throws ValidationException
     */
    public function update(SellerAutomationRule $rule, array $input, SellerPrincipal $principal): SellerAutomationRule
    {
        $before = $rule->only(['name', 'trigger', 'action', 'trigger_settings', 'action_settings', 'scope', 'status']);
        $data = $this->validate($input, $principal, $rule);

        // A rule the marketplace stopped stays stopped while it is edited. The seller may fix it —
        // that is what the edit is for — but the decision to let it run again is not theirs.
        $heldByMarketplace = $rule->isSuspendedByMarketplace();

        $rule->forceFill([
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'action' => $data['action'],
            'trigger_settings' => $data['trigger_settings'],
            'action_settings' => $data['action_settings'],
            'scope' => $data['scope'],
            'status' => $heldByMarketplace ? SellerAutomationRule::STATUS_SUSPENDED : $data['status'],
            'max_actions_per_run' => $data['max_actions_per_run'],
            'cooldown_minutes' => $data['cooldown_minutes'],
            // A rule that has been rewritten is a different rule. Whatever it did wrong before is
            // no longer evidence against it, and holding its old failures against it would leave a
            // seller unable to fix their own rule.
            'consecutive_failures' => 0,
            'suspended_at' => $heldByMarketplace ? $rule->suspended_at : null,
            'suspension_reason' => $heldByMarketplace ? $rule->suspension_reason : null,
            'suspended_by' => $heldByMarketplace ? $rule->suspended_by : null,
        ])->save();

        $this->audit->record(
            action: 'seller.automation_rule_updated',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            before: $before,
            after: $rule->only(['name', 'trigger', 'action', 'trigger_settings', 'action_settings', 'scope', 'status']),
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        return $rule;
    }

    /**
     * Switch a rule on or off, or clear a suspension.
     *
     * Clearing a suspension is the same call as switching it on, deliberately: the seller sees why
     * it was stopped and turns it back on in one act, rather than acknowledging a warning and then
     * forgetting the second step. That holds for the breaker only — a rule the marketplace stopped
     * is not the seller's to restart, or stopping it would mean nothing.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function setStatus(SellerAutomationRule $rule, string $status, SellerPrincipal $principal): array
    {
        if (!in_array($status, SellerAutomationRule::SELLER_SETTABLE_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'automation_reason_status_not_settable'];
        }

        if ($rule->isSuspendedByMarketplace()) {
            return ['ok' => false, 'reason' => 'automation_reason_suspended_by_marketplace'];
        }

        $was = $rule->status;
        $reason = $rule->suspension_reason;

        $rule->forceFill([
            'status' => $status,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by' => null,
            'consecutive_failures' => $status === SellerAutomationRule::STATUS_ACTIVE ? 0 : $rule->consecutive_failures,
        ])->save();

        $this->audit->record(
            action: 'seller.automation_rule_status_changed',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            before: ['status' => $was, 'suspension_reason' => $reason],
            after: ['status' => $status],
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        return ['ok' => true];
    }

    public function delete(SellerAutomationRule $rule, SellerPrincipal $principal): void
    {
        $this->audit->record(
            action: 'seller.automation_rule_deleted',
            subject: ['type' => 'seller_automation_rule', 'id' => $rule->id],
            before: $rule->only(['name', 'trigger', 'action', 'status']),
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        // The runs and actions stay. They are the record of what happened to the shop, and deleting
        // the rule does not un-happen it.
        $rule->delete();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input, SellerPrincipal $principal, ?SellerAutomationRule $existing = null): array
    {
        $base = Validator::make($input, [
            'name' => 'required|string|max:160',
            'trigger' => 'required|string|max:60',
            'action' => 'required|string|max:60',
            'status' => 'nullable|in:' . implode(',', SellerAutomationRule::SELLER_SETTABLE_STATUSES),
            'max_actions_per_run' => 'nullable|integer|min:1|max:' . self::MAX_ACTIONS_PER_RUN,
            'cooldown_minutes' => 'nullable|integer|min:5|max:10080',
            'trigger_settings' => 'nullable|array',
            'action_settings' => 'nullable|array',
            'scope' => 'nullable|array',
        ])->validate();

        $trigger = $this->registry->trigger($base['trigger']);
        $action = $this->registry->action($base['action']);

        if (!$trigger || !$action) {
            throw ValidationException::withMessages(['trigger' => translate('automation_error_unknown_trigger_or_action')]);
        }

        if (!$this->registry->accepts($trigger->key(), $action->key())) {
            throw ValidationException::withMessages(['action' => translate('automation_error_action_cannot_act_on_this_trigger')]);
        }

        // The permission belongs to the action, because the action is what changes the shop. A staff
        // member who may look at products but not change them may not write a rule that changes them
        // while they are not looking.
        if (!$principal->can($action->permission())) {
            throw ValidationException::withMessages(['action' => translate('automation_error_permission')]);
        }

        return [
            'name' => $base['name'],
            'trigger' => $trigger->key(),
            'action' => $action->key(),
            'trigger_settings' => $this->settings($input['trigger_settings'] ?? [], $trigger->rules(), 'trigger_settings'),
            'action_settings' => $this->settings($input['action_settings'] ?? [], $action->rules(), 'action_settings'),
            // Cleaned rather than stored as given, so a scope naming nothing is stored as nothing
            // and cannot later read as "this rule is narrowed" on a screen.
            'scope' => RuleScope::clean($this->settings($input['scope'] ?? [], RuleScope::rules(), 'scope')),
            // An edit that does not mention a field leaves that field alone. Falling back to the
            // creation defaults would mean renaming a rule silently resumed it and reset the two
            // limits that exist to stop it running away.
            'status' => $base['status'] ?? $this->keptStatus($existing),
            'max_actions_per_run' => (int) ($base['max_actions_per_run'] ?? $existing?->max_actions_per_run ?? 50),
            'cooldown_minutes' => (int) ($base['cooldown_minutes'] ?? $existing?->cooldown_minutes ?? 15),
        ];
    }

    /**
     * The status an edit that does not name one leaves behind.
     *
     * A seller who renames a rule they had switched off has not asked for it to start running, and
     * a rule the platform suspended never comes back through an edit: clearing a suspension is a
     * deliberate act made with the reason on screen.
     */
    private function keptStatus(?SellerAutomationRule $existing): string
    {
        if ($existing === null || $existing->status === SellerAutomationRule::STATUS_ACTIVE) {
            return SellerAutomationRule::STATUS_ACTIVE;
        }

        return SellerAutomationRule::STATUS_PAUSED;
    }

    /**
     * Validate one settings bag against its owner's rules, and keep only what those rules name.
     *
     * @throws ValidationException
     */
    private function settings(array $given, array $rules, string $field): array
    {
        if ($rules === []) {
            return [];
        }

        // Prefixed so a failure reads `trigger_settings.threshold` rather than a bare `threshold`
        // the caller cannot place — two bags in one request would otherwise be indistinguishable.
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed["{$field}.{$key}"] = $rule;
        }

        $validated = Validator::make([$field => $given], $prefixed)->validate();

        // Anything the trigger or action did not ask for is dropped rather than stored. A settings
        // bag that quietly carries keys nothing reads is how a rule ends up appearing to be
        // configured for something it never does.
        return array_intersect_key($validated[$field] ?? [], $rules);
    }
}
