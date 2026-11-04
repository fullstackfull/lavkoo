<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerAutomationRun;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\AutomationEngine;
use App\Services\SellerAutomation\AutomationRegistry;
use App\Services\SellerAutomation\SellerAutomationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The seller's own rules, and the complete record of what they did.
 *
 * Every read is scoped to the shop on the token and every rule is fetched by id *and* seller, so an
 * id from another shop is not found rather than forbidden — an endpoint that distinguishes the two
 * is a way to enumerate other people's rules.
 */
class SellerAutomationController extends Controller
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly AutomationEngine $engine,
        private readonly SellerAutomationRuleService $rules,
    ) {
    }

    #[ApiDoc(
        summary: 'What a rule can be built out of',
        description: 'Every trigger, every action, which pairs are legal, and which settings each one '
            . 'requires — each setting described as the form control it needs, derived from the rule '
            . 'that will validate it. The screen builds its form from this rather than hard-coding a '
            . 'list, so a trigger added on the server appears in the app without shipping a new build.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function catalogue(Request $request): JsonResponse
    {
        // Built for whoever is asking. A staff member who may not change products is told so on the
        // action itself, rather than discovering it when the rule they wrote is refused.
        return response()->json($this->registry->catalogue($this->principal($request)), 200);
    }

    #[ApiDoc(
        summary: 'The shop\'s rules',
        description: 'Newest first, with what each one has actually done: how many times it ran, how '
            . 'many things it changed, when it last did anything, and — when it has been stopped — why. '
            . 'A rule that has run fifty times and changed nothing is visible as exactly that.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        $rules = SellerAutomationRule::where('seller_id', $request->seller->id)
            ->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->get('limit', 25))));

        return response()->json([
            'total_size' => $rules->total(),
            'limit' => $rules->perPage(),
            'offset' => $rules->currentPage(),
            'rules' => collect($rules->items())->map(fn (SellerAutomationRule $rule) => $this->present($rule))->all(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'One rule, with its recent runs',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function show(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        return response()->json([
            'rule' => $this->present($rule),
            'runs' => SellerAutomationRun::where('rule_id', $rule->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (SellerAutomationRun $run) => $this->presentRun($run))
                ->all(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Write a rule',
        description: 'The settings are validated against the trigger and the action the rule names, so '
            . 'a rule cannot be stored holding settings its own trigger does not understand. The '
            . 'permission checked is the action\'s, because the action is what changes the shop.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function store(Request $request): JsonResponse
    {
        try {
            $rule = $this->rules->create($request->all(), $this->principal($request));
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        }

        return response()->json(['message' => translate('automation_rule_created'), 'rule' => $this->present($rule)], 201);
    }

    #[ApiDoc(
        summary: 'Rewrite a rule',
        description: 'A rewritten rule starts clean: its failure count is reset and any suspension is '
            . 'cleared, because the rule that failed is not the rule that now exists.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function update(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        try {
            $this->rules->update($rule, $request->all(), $this->principal($request));
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        }

        return response()->json(['message' => translate('automation_rule_updated'), 'rule' => $this->present($rule)], 200);
    }

    #[ApiDoc(
        summary: 'Switch a rule on or off, or restart a stopped one',
        description: 'Only active and paused are settable. Suspended is the platform\'s answer, not the '
            . 'seller\'s — switching a suspended rule back to active is how it is cleared, deliberately, '
            . 'with the reason it stopped still on the screen.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function setStatus(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        $result = $this->rules->setStatus($rule, (string) $request->get('status'), $this->principal($request));

        if (!$result['ok']) {
            return response()->json(['errors' => [
                ['code' => 'status', 'message' => translate($result['reason'])],
            ]], 403);
        }

        return response()->json(['message' => translate('automation_rule_updated'), 'rule' => $this->present($rule)], 200);
    }

    #[ApiDoc(
        summary: 'Delete a rule',
        description: 'The runs and the actions stay. They are the record of what happened to the shop, '
            . 'and deleting the rule does not un-happen it.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function destroy(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        $this->rules->delete($rule, $this->principal($request));

        return response()->json(['message' => translate('automation_rule_deleted')], 200);
    }

    #[ApiDoc(
        summary: 'What this rule would do right now',
        description: 'Runs the trigger and the action\'s own planning step and changes nothing. The '
            . 'preview and the real run share the same code, so what is shown here is what would '
            . 'happen — including the rows the rule would decline to touch, and why.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function preview(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        return response()->json($this->engine->preview($rule), 200);
    }

    #[ApiDoc(
        summary: 'Run a rule now',
        description: 'The same run the scheduler would make, on demand, ignoring the cooldown. Useful '
            . 'straight after writing a rule, when waiting a quarter of an hour to find out whether it '
            . 'works is the difference between using automation and not.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function runNow(Request $request, $id): JsonResponse
    {
        $rule = $this->find($request, $id);

        if (!$rule) {
            return $this->notFound();
        }

        if ($rule->isSuspended()) {
            return response()->json(['errors' => [
                ['code' => 'rule', 'message' => translate('automation_reason_suspended')],
            ]], 403);
        }

        $run = $this->engine->run($rule);

        return response()->json([
            'message' => translate('automation_rule_ran'),
            'run' => $this->presentRun($run),
            'rule' => $this->present($rule->refresh()),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Everything automation has done to this shop',
        description: 'One row per record touched, with the value before and after, filterable by rule. '
            . 'This is the answer to "who changed this" when the answer is not a person.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function activity(Request $request): JsonResponse
    {
        $actions = SellerAutomationAction::where('seller_id', $request->seller->id)
            ->when($request->filled('rule_id'), fn ($query) => $query->where('rule_id', (int) $request->get('rule_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('limit', 25))));

        return response()->json([
            'total_size' => $actions->total(),
            'limit' => $actions->perPage(),
            'offset' => $actions->currentPage(),
            'actions' => collect($actions->items())->map(fn (SellerAutomationAction $action) => [
                'id' => $action->id,
                'rule_id' => $action->rule_id,
                'action' => $action->action,
                'subject_type' => $action->subject_type,
                'subject_id' => $action->subject_id,
                'subject_label' => $action->subject_label,
                'status' => $action->status,
                'reason' => $action->reason,
                'before' => $action->before,
                'after' => $action->after,
                'revertible' => $action->isRevertible(),
                'reverted_at' => $action->reverted_at,
                'created_at' => $action->created_at,
            ])->all(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Undo one automated change',
        description: 'Restores only the columns the action that made the change declares it owns, from '
            . 'the value recorded at the time. Undo is not a general-purpose write: a trail row cannot '
            . 'be used to set an arbitrary column on an arbitrary product.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function revert(Request $request, $id): JsonResponse
    {
        $record = SellerAutomationAction::where('seller_id', $request->seller->id)->find($id);

        if (!$record) {
            return $this->notFound();
        }

        $result = $this->engine->revert($record, $this->principal($request));

        if (!$result['ok']) {
            return response()->json(['errors' => [
                ['code' => 'revert', 'message' => translate($result['reason'])],
            ]], 403);
        }

        return response()->json(['message' => translate('automation_action_reverted')], 200);
    }

    private function present(SellerAutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'action' => $rule->action,
            'trigger_settings' => $rule->trigger_settings ?? [],
            'action_settings' => $rule->action_settings ?? [],
            'scope' => $rule->scope ?? [],
            'status' => $rule->status,
            'max_actions_per_run' => $rule->max_actions_per_run,
            'cooldown_minutes' => $rule->cooldown_minutes,
            'run_count' => $rule->run_count,
            'applied_count' => $rule->applied_count,
            'consecutive_failures' => $rule->consecutive_failures,
            'last_run_at' => $rule->last_run_at,
            'last_fired_at' => $rule->last_fired_at,
            'suspended_at' => $rule->suspended_at,
            'suspension_reason' => $rule->suspension_reason,
            // The console shows a stopped rule differently depending on who stopped it: one of the
            // two the seller can clear themselves, the other they cannot.
            'suspended_by' => $rule->suspended_by,
            'created_at' => $rule->created_at,
        ];
    }

    private function presentRun(SellerAutomationRun $run): array
    {
        return [
            'id' => $run->id,
            'outcome' => $run->outcome,
            'matched_count' => $run->matched_count,
            'applied_count' => $run->applied_count,
            'skipped_count' => $run->skipped_count,
            'failed_count' => $run->failed_count,
            'message' => $run->message,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
        ];
    }

    private function find(Request $request, $id): ?SellerAutomationRule
    {
        return SellerAutomationRule::where('seller_id', $request->seller->id)->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'rule', 'message' => translate('automation_rule_not_found')],
        ]], 404);
    }

    private function validationFailed(ValidationException $exception): JsonResponse
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[] = ['code' => $field, 'message' => $messages[0]];
        }

        return response()->json(['errors' => $errors], 403);
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }
}
