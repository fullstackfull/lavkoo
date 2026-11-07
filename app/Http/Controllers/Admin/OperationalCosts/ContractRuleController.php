<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CalcMethod;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\Concerns\RedirectsFromLockedContracts;
use App\Http\Controllers\Admin\OperationalCosts\Forms\ContractRuleForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalCosts\ContractRuleRequest;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostRule;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The price rules of a draft contract. A rule is added, changed and removed only while its contract is a draft; the figures it holds are the ones typed here,
 * and RuleValidator says in words what is wrong with them.
 */
class ContractRuleController extends Controller
{
    use RedirectsFromLockedContracts;

    public function __construct(private readonly ContractService $contracts)
    {
    }

    public function create(int $contract): View|RedirectResponse
    {
        $record = $this->contract($contract);

        return $record->status->isEditable() ? $this->form($record, null) : $this->lockedContract($record);
    }

    public function store(ContractRuleRequest $request, int $contract): RedirectResponse
    {
        $record = $this->contract($contract);

        try {
            $this->contracts->addRule($record, $request->ruleData(), auth('admin')->id());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('rule_added'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    public function edit(int $contract, int $rule): View|RedirectResponse
    {
        $record = $this->contract($contract);

        return $record->status->isEditable() ? $this->form($record, $this->rule($record, $rule)) : $this->lockedContract($record);
    }

    public function update(ContractRuleRequest $request, int $contract, int $rule): RedirectResponse
    {
        $record = $this->contract($contract);

        try {
            $this->contracts->updateRule($this->rule($record, $rule), $request->ruleData(), auth('admin')->id());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('rule_saved'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    public function destroy(int $contract, int $rule): RedirectResponse
    {
        $record = $this->contract($contract);

        try {
            $this->contracts->removeRule($this->rule($record, $rule), auth('admin')->id());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('rule_removed'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    private function form(OperationalCostContract $contract, ?OperationalCostRule $rule): View
    {
        return view('admin-views.operational-costs.contracts.rule-form', [
            'contract' => $contract,
            'rule' => $rule,
            'values' => ContractRuleForm::defaults($rule),
            'components' => CostComponent::contractPriced(),
            'methods' => CalcMethod::cases(),
            'channels' => SalesChannel::cases(),
        ]);
    }

    private function contract(int $id): OperationalCostContract
    {
        return OperationalCostContract::query()->findOrFail($id);
    }

    private function rule(OperationalCostContract $contract, int $id): OperationalCostRule
    {
        return $contract->rules()->findOrFail($id);
    }
}
