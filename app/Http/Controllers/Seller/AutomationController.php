<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerAutomationRule;
use App\Services\SellerAutomation\AutomationEngine;
use App\Services\SellerAutomation\AutomationRegistry;
use App\Services\SellerAutomation\SellerAutomationRuleService;
use App\Services\SellerCenter\Automation\RulePresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The seller's rules: what they are, what they would do, and switching them on (handoff 08 A1–A2).
 *
 * Every write goes through `SellerAutomationRuleService` — the same service the seller app's API
 * calls — rather than repeating its validation here. That is what makes a rule written on a phone
 * and a rule written at a desk the same rule, checked the same way, with the same permission and
 * the same audit row (PART 7).
 */
class AutomationController extends SellerCenterController
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly AutomationEngine $engine,
        private readonly SellerAutomationRuleService $rules,
        private readonly RulePresenter $presenter,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);

        $rules = SellerAutomationRule::where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('seller-views.automation.index', [
            'rules' => $rules,
            'presented' => $this->presenter->collection($rules->items()),
            'state' => $this->listState($rules->total(), false),
        ]);
    }

    public function create(Request $request): View
    {
        return view('seller-views.automation.builder', $this->builderData($request, null));
    }

    public function edit(Request $request, int $rule): View
    {
        $model = $this->findOrFail($request, $rule);

        return view('seller-views.automation.builder', $this->builderData($request, $model));
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $model = $this->rules->create($request->all(), $this->principal($request));
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('seller.automation.edit', $model->id)
            ->with('success', translate('automation_rule_created'));
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $model = $this->findOrFail($request, $rule);

        try {
            $this->rules->update($model, $request->all(), $this->principal($request));
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('seller.automation.edit', $model->id)
            ->with('success', translate('automation_rule_updated'));
    }

    public function setStatus(Request $request, int $rule): RedirectResponse
    {
        $model = $this->findOrFail($request, $rule);
        $result = $this->rules->setStatus($model, (string) $request->input('status'), $this->principal($request));

        return $result['ok']
            ? back()->with('success', translate('automation_rule_updated'))
            : back()->with('error', translate($result['reason']));
    }

    public function destroy(Request $request, int $rule): RedirectResponse
    {
        $model = $this->findOrFail($request, $rule);
        $this->rules->delete($model, $this->principal($request));

        return redirect()
            ->route('seller.automation.index')
            ->with('success', translate('automation_rule_deleted'));
    }

    /**
     * What this rule would do right now, changing nothing.
     *
     * Answers with the engine's own preview, which shares its code with the real run — a dry run
     * built from different code would be a demonstration, not a preview.
     */
    public function preview(Request $request, int $rule): View
    {
        $model = $this->findOrFail($request, $rule);

        return view('seller-views.automation.preview', [
            'rule' => $this->presenter->one($model),
            'preview' => $this->engine->preview($model),
        ]);
    }

    public function runNow(Request $request, int $rule): RedirectResponse
    {
        $model = $this->findOrFail($request, $rule);

        if ($model->isSuspended()) {
            return back()->with('error', translate('automation_reason_suspended'));
        }

        $run = $this->engine->run($model);

        return redirect()
            ->route('seller.automation.history', ['run' => $run->id])
            ->with('success', translate('automation_rule_ran'));
    }

    /** @return array<string, mixed> */
    private function builderData(Request $request, ?SellerAutomationRule $model): array
    {
        $principal = $this->principal($request);

        // The form is built from the server's catalogue, for this principal: an action they may
        // not perform arrives already marked as one they cannot automate (handoff 08 A2).
        $catalogue = $this->registry->catalogue($principal);

        $trigger = $this->chosenTrigger($request, $model, $catalogue);
        $action = $this->chosenAction($request, $model, $catalogue, $trigger);

        return [
            'rule' => $model,
            'presented' => $model === null ? null : $this->presenter->one($model),
            'catalogue' => $catalogue,
            'chosenTrigger' => $trigger,
            'chosenAction' => $action,
            // Handed to the page so its plain-words sentence stays live while the seller types,
            // without the browser ever assembling one out of separate words.
            'sentenceTemplates' => $this->presenter->sentenceTemplates($trigger, $action),
            'maxActionsPerRun' => SellerAutomationRuleService::MAX_ACTIONS_PER_RUN,
        ];
    }

    /**
     * Which trigger the form is showing: what the seller just picked, what they submitted, what the
     * rule already says, or the first one the server offers.
     */
    private function chosenTrigger(Request $request, ?SellerAutomationRule $model, array $catalogue): string
    {
        $keys = array_column($catalogue['triggers'], 'key');
        $wanted = (string) ($request->old('trigger') ?? $request->query('trigger') ?? $model?->trigger ?? '');

        return in_array($wanted, $keys, true) ? $wanted : (string) ($keys[0] ?? '');
    }

    /**
     * An action the chosen trigger cannot feed is not a rule with a mistake in it — it is not a
     * rule at all, so the choice falls back to one the server declares legal for this trigger.
     */
    private function chosenAction(Request $request, ?SellerAutomationRule $model, array $catalogue, string $trigger): string
    {
        $allowed = collect($catalogue['triggers'])->firstWhere('key', $trigger)['actions'] ?? [];
        $wanted = (string) ($request->old('action') ?? $request->query('action') ?? $model?->action ?? '');

        return in_array($wanted, $allowed, true) ? $wanted : (string) ($allowed[0] ?? '');
    }

    private function findOrFail(Request $request, int $rule): SellerAutomationRule
    {
        // By id *and* seller, so an id from another shop is not found rather than forbidden — the
        // difference between the two answers is a way to enumerate other people's rules.
        $model = SellerAutomationRule::where('seller_id', $this->sellerId($request))->find($rule);

        abort_if($model === null, 404);

        return $model;
    }
}
