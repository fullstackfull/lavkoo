<?php

namespace App\Services\SellerCenter\Automation;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerAutomationRule;
use App\Services\SellerAutomation\RuleScope;
use App\Services\SellerCenter\Copy;
use App\Services\SellerCenter\Status;
use Illuminate\Support\Facades\Schema;

/**
 * A rule as a person reads it (handoff 08 A1–A2).
 *
 * Three things a rule row has to say, none of which the stored row says by itself.
 *
 * **What it does**, as one sentence rather than two identifiers. "low_stock → set_discount" is the
 * database's account of the rule; "When stock falls to 5 or fewer, mark the product down by 20%"
 * is the seller's.
 *
 * **Where it is pointed**, resolved to names. A scope stored as `{"brand_ids":[3]}` means nothing
 * on a screen; "Brand: MEDEE" does. Names are looked up in one query per kind, not per rule.
 *
 * **Why it stopped**, distinguishing the two suspensions. The breaker is the seller's to clear and
 * the marketplace's is not, so they cannot share a badge — a resume button that always refuses is
 * worse than no button.
 */
class RulePresenter
{
    /** @var array<string, array<int, string>> resolved scope names, kept for one request */
    private array $names = [];

    /**
     * @param  iterable<SellerAutomationRule>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function collection(iterable $rules): array
    {
        $rules = collect($rules);

        $this->resolveNames($rules);

        return $rules->map(fn (SellerAutomationRule $rule) => $this->one($rule))->all();
    }

    /** @return array<string, mixed> */
    public function one(SellerAutomationRule $rule): array
    {
        $scope = RuleScope::clean($rule->scope);

        return [
            'model' => $rule,
            'id' => $rule->id,
            'name' => $rule->name,
            'sentence' => $this->sentence($rule),
            'trigger_label' => translate('automation_trigger_' . $rule->trigger),
            'action_label' => translate('automation_action_' . $rule->action),
            'scope' => $scope,
            'scope_label' => $this->scopeLabel($scope),
            'status' => $rule->status,
            'status_tone' => Status::tone($rule->status),
            'stopped_by_marketplace' => $rule->isSuspendedByMarketplace(),
            'may_resume' => $rule->isSuspended() && !$rule->isSuspendedByMarketplace(),
            'suspension_reason' => $rule->suspension_reason,
            'run_count' => (int) $rule->run_count,
            'applied_count' => (int) $rule->applied_count,
            'last_run_at' => $rule->last_run_at,
            // "Ran, changed nothing" is information, not a fault: a rule that watches for a
            // condition which has not happened is working exactly as written (handoff 08 A1).
            'ran_without_acting' => (int) $rule->run_count > 0 && (int) $rule->applied_count === 0,
            'success_rate' => $this->successRate($rule),
        ];
    }

    /**
     * The rule as one sentence, with its own numbers in it.
     *
     * Assembled from three whole-sentence keys rather than glued together from words, because the
     * order of "when X, do Y, at most N per run" is not the same in Arabic.
     */
    public function sentence(SellerAutomationRule $rule): string
    {
        return $this->sentenceFrom(
            trigger: (string) $rule->trigger,
            action: (string) $rule->action,
            triggerSettings: $rule->trigger_settings ?? [],
            actionSettings: $rule->action_settings ?? [],
            cap: (int) $rule->max_actions_per_run,
            cooldownMinutes: (int) $rule->cooldown_minutes,
        );
    }

    /**
     * The same sentence for a rule that does not exist yet, so the builder can say what it is about
     * to write before it writes it.
     *
     * @param  array<string, mixed>  $triggerSettings
     * @param  array<string, mixed>  $actionSettings
     */
    public function sentenceFrom(
        string $trigger,
        string $action,
        array $triggerSettings,
        array $actionSettings,
        int $cap,
        int $cooldownMinutes,
    ): string {
        return Copy::line('automation_rule_sentence', [
            'when' => Copy::line('automation_when_' . $trigger, $this->placeholders($triggerSettings)),
            // A clause, not a sentence: it is the second half of one.
            'then' => Copy::clause('automation_then_' . $action, $this->placeholders($actionSettings)),
            'cap' => $cap,
            'cooldown' => Copy::duration($cooldownMinutes),
        ]);
    }

    /**
     * The three untranslated-into-values templates the builder needs to keep its sentence live.
     *
     * Handed to the page as data so the browser only substitutes numbers a seller is typing; it
     * never assembles a sentence out of words, which is what would break the moment the reader is
     * reading right to left.
     *
     * @return array{frame: string, when: string, then: string}
     */
    public function sentenceTemplates(string $trigger, string $action): array
    {
        return [
            'frame' => (string) translate('automation_rule_sentence'),
            'when' => (string) translate('automation_when_' . $trigger),
            // Lower-cased here rather than in the browser: it is the same rule `Copy::clause()`
            // applies, and it belongs where the language decision is made.
            'then' => lcfirst((string) translate('automation_then_' . $action)),
        ];
    }

    /** Percentage of runs that changed something, or null when it has never run. */
    public function successRate(SellerAutomationRule $rule): ?float
    {
        $runs = (int) $rule->run_count;

        // Never run is not zero per cent. A rule written a minute ago has no rate, and rendering
        // one would read as failure (handoff README, `null` is never `0`).
        return $runs === 0 ? null : round(min(100, (int) $rule->applied_count / $runs * 100), 1);
    }

    /** @param array<string, array<int, int>> $scope */
    public function scopeLabel(array $scope): ?string
    {
        if ($scope === []) {
            return null;
        }

        $parts = [];

        foreach (['brand_ids' => 'brands', 'category_ids' => 'categories', 'product_ids' => 'products'] as $field => $kind) {
            if (!isset($scope[$field])) {
                continue;
            }

            $names = array_values(array_filter(array_map(
                fn (int $id) => $this->names[$kind][$id] ?? null,
                $scope[$field],
            )));

            $shown = array_slice($names, 0, 2);
            $rest = count($scope[$field]) - count($shown);

            $parts[] = Copy::line('automation_scope_' . $kind, [
                'names' => $shown === [] ? Copy::line('n_selected', ['count' => count($scope[$field])]) : implode('، ', $shown),
                'more' => $rest > 0 ? Copy::line('and_n_more', ['count' => $rest]) : '',
            ]);
        }

        return trim(implode(' · ', $parts));
    }

    /**
     * Every id named by every rule's scope, resolved to a name in one query per kind.
     *
     * @param  \Illuminate\Support\Collection<int, SellerAutomationRule>  $rules
     */
    private function resolveNames($rules): void
    {
        $wanted = ['brands' => [], 'categories' => [], 'products' => []];

        foreach ($rules as $rule) {
            $scope = RuleScope::clean($rule->scope);
            $wanted['brands'] = array_merge($wanted['brands'], $scope['brand_ids'] ?? []);
            $wanted['categories'] = array_merge($wanted['categories'], $scope['category_ids'] ?? []);
            $wanted['products'] = array_merge($wanted['products'], $scope['product_ids'] ?? []);
        }

        $models = ['brands' => Brand::class, 'categories' => Category::class, 'products' => Product::class];

        foreach ($wanted as $kind => $ids) {
            $ids = array_values(array_unique($ids));

            if ($ids === [] || !Schema::hasTable($kind)) {
                continue;
            }

            $this->names[$kind] = $models[$kind]::withoutGlobalScope('translate')
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->map(fn ($name) => (string) $name)
                ->all();
        }
    }

    /**
     * A settings bag as `:placeholder` values, with the keys the copy uses.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string|int|float>
     */
    private function placeholders(array $settings): array
    {
        $placeholders = [];

        foreach ($settings as $key => $value) {
            $placeholders[$key] = is_scalar($value) ? $value : '';
        }

        // A `discount_type` is a word the seller reads, not a database enum. Translated here so
        // every sentence that mentions one says the same thing.
        if (isset($settings['discount_type'])) {
            $placeholders['discount_type'] = Copy::clause('automation_discount_' . $settings['discount_type']);
        }

        return $placeholders;
    }
}
