<?php

namespace App\Services\SellerAutomation;

use App\Models\SellerAutomationRule;
use App\Services\Marketplace\SellerPrincipal;

/**
 * What a seller is allowed to build a rule out of.
 *
 * Registering triggers and actions in one place is what keeps the catalogue endpoint, the validation
 * and the engine from drifting: the screen offers exactly what the validator accepts and exactly
 * what the engine can run, because all three read this.
 *
 * Combinations are checked rather than assumed. A trigger that selects orders and an action that
 * changes listings is not a rule with a bug in it; it is not a rule at all, and refusing it at
 * creation is far cheaper than discovering it at three in the morning.
 */
class AutomationRegistry
{
    /** @var array<string, AutomationTrigger> */
    private array $triggers = [];

    /** @var array<string, AutomationAction> */
    private array $actions = [];

    /**
     * @param  iterable<AutomationTrigger>  $triggers
     * @param  iterable<AutomationAction>  $actions
     */
    public function __construct(iterable $triggers = [], iterable $actions = [])
    {
        foreach ($triggers as $trigger) {
            $this->triggers[$trigger->key()] = $trigger;
        }

        foreach ($actions as $action) {
            $this->actions[$action->key()] = $action;
        }
    }

    public function trigger(string $key): ?AutomationTrigger
    {
        return $this->triggers[$key] ?? null;
    }

    public function action(string $key): ?AutomationAction
    {
        return $this->actions[$key] ?? null;
    }

    public function triggerFor(SellerAutomationRule $rule): ?AutomationTrigger
    {
        return $this->trigger($rule->trigger);
    }

    public function actionFor(SellerAutomationRule $rule): ?AutomationAction
    {
        return $this->action($rule->action);
    }

    public function accepts(string $triggerKey, string $actionKey): bool
    {
        $trigger = $this->trigger($triggerKey);
        $action = $this->action($actionKey);

        if (!$trigger || !$action) {
            return false;
        }

        return in_array($trigger->subjectType(), $action->subjectTypes(), true);
    }

    /**
     * How much a seller has to think before switching this action on (handoff 08 A2).
     *
     * Decided here rather than on the screen, and only from what the server actually knows: an
     * action nobody may perform cannot be automated, and an action whose changes can be put back
     * runs on its own. There is deliberately no third answer — a badge saying "needs confirmation"
     * would promise a confirmation step that nothing implements.
     */
    public function classify(AutomationAction $action, ?SellerPrincipal $principal): array
    {
        if ($principal !== null && !$principal->can($action->permission())) {
            return ['class' => 'restricted', 'reason' => 'automation_class_restricted_reason'];
        }

        return $action->revertibleColumns() === []
            ? ['class' => 'restricted', 'reason' => 'automation_class_not_revertible_reason']
            : ['class' => 'safe', 'reason' => null];
    }

    /**
     * The catalogue as a screen needs it: every trigger, every action, and which pairs are legal.
     *
     * Given a principal, each action also carries whether that principal may automate it, so a
     * screen offers what the seller in front of it can actually use rather than what the shop owner
     * could.
     *
     * @return array{triggers: array<int, array>, actions: array<int, array>, scope: array<int, array>}
     */
    public function catalogue(?SellerPrincipal $principal = null): array
    {
        return [
            'triggers' => array_values(array_map(fn (AutomationTrigger $trigger) => [
                'key' => $trigger->key(),
                'subject_type' => $trigger->subjectType(),
                'settings' => array_keys($trigger->rules()),
                'required_settings' => $this->requiredKeys($trigger->rules()),
                'fields' => SettingField::describe($trigger->rules()),
                'actions' => array_values(array_keys(array_filter(
                    $this->actions,
                    fn (AutomationAction $action) => in_array($trigger->subjectType(), $action->subjectTypes(), true),
                ))),
            ], $this->triggers)),
            'actions' => array_values(array_map(fn (AutomationAction $action) => [
                'key' => $action->key(),
                'permission' => $action->permission(),
                'subject_types' => $action->subjectTypes(),
                'settings' => array_keys($action->rules()),
                'required_settings' => $this->requiredKeys($action->rules()),
                'fields' => SettingField::describe($action->rules()),
                'revertible_columns' => $action->revertibleColumns(),
                'classification' => $this->classify($action, $principal),
            ], $this->actions)),
            // The same shape as a settings bag, so a screen renders the scope with the code it
            // already has for trigger and action settings.
            'scope' => SettingField::describe(array_filter(
                RuleScope::rules(),
                fn (string $key) => !str_contains($key, '.'),
                ARRAY_FILTER_USE_KEY,
            )),
        ];
    }

    /** @return array<int, string> */
    private function requiredKeys(array $rules): array
    {
        return array_values(array_keys(array_filter(
            $rules,
            fn ($rule) => str_contains(is_array($rule) ? implode('|', $rule) : (string) $rule, 'required'),
        )));
    }
}
