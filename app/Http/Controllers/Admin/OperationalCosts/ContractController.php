<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\OperationalCosts\ContractStatus;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\Concerns\RedirectsFromLockedContracts;
use App\Http\Controllers\Admin\OperationalCosts\Filters\QueryInput;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalCosts\ContractRequest;
use App\Models\Admin;
use App\Models\OperationalCostContract;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Support\FxConverter;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * The price lists of the companies that charge us. A contract is typed in as a draft with no price in it; once it is active it never changes, and a new price
 * list is a new version that takes over from its start date. Every step leaves a revision (who, when, why).
 */
class ContractController extends Controller
{
    use RedirectsFromLockedContracts;

    private const PROVIDER_OPTION_LIMIT = 100;

    public function __construct(
        private readonly ContractService $contracts,
        private readonly FxConverter $fx,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'party_type' => QueryInput::text($request, 'party_type'),
            'provider' => QueryInput::text($request, 'provider'),
            'status' => ContractStatus::tryFrom((string) QueryInput::text($request, 'status'))?->value,
            'q' => QueryInput::text($request, 'q'),
            'seller' => SellerScope::resolve($request),
        ];

        $query = OperationalCostContract::query()->withCount('rules')
            ->when($filters['party_type'] !== null, static fn (Builder $query): Builder => $query->where('party_type', $filters['party_type']))
            ->when($filters['provider'] !== null, static fn (Builder $query): Builder => $query->where('provider_code', $filters['provider']))
            ->when($filters['status'] !== null, static fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['q'] !== null, static fn (Builder $query): Builder => $query->where(static fn (Builder $inner): Builder => $inner
                ->where('party_name', 'like', '%' . $filters['q'] . '%')->orWhere('reference', 'like', '%' . $filters['q'] . '%')->orWhere('provider_code', 'like', '%' . $filters['q'] . '%')));

        return view('admin-views.operational-costs.contracts.index', [
            'contracts' => SellerScope::apply($query, $filters['seller'])->orderBy('provider_code')->orderByDesc('root_id')->orderByDesc('version')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'providers' => OperationalCostContract::query()->distinct()->orderBy('provider_code')->limit(self::PROVIDER_OPTION_LIMIT)->pluck('provider_code'),
            'sellers' => SellerScope::options('operational_cost_contracts'),
        ]);
    }

    public function create(): View
    {
        return view('admin-views.operational-costs.contracts.form', ['contract' => null] + $this->formOptions());
    }

    public function store(ContractRequest $request): RedirectResponse
    {
        try {
            $contract = $this->contracts->createDraft($request->contractData(), auth('admin')->id(), $request->sellerId());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('contract_draft_created'));

        return redirect()->route('admin.operational-costs.contracts.show', $contract->id);
    }

    public function show(Request $request, int $contract): View
    {
        $record = $this->find($contract);
        $versions = OperationalCostContract::where('root_id', $record->root_id)->orderBy('version')->get();
        $revisions = $record->revisions()->get();
        $day = QueryInput::date($request, 'on');

        return view('admin-views.operational-costs.contracts.show', [
            'contract' => $record,
            'rules' => $record->rules()->orderBy('service_type')->orderBy('priority')->orderBy('id')->get(),
            'versions' => $versions,
            'revisions' => $revisions,
            'actors' => Admin::whereIn('id', $revisions->pluck('actor_id')->filter()->unique())->pluck('name', 'id'),
            'isLatest' => $versions->last()?->id === $record->id,
            'checkedOn' => $day,
            'inForce' => $day === null ? null : $this->contracts->contractFor($record->provider_code, $day, (int) $record->seller_id, $record->currency),
        ]);
    }

    public function edit(int $contract): View|RedirectResponse
    {
        $record = $this->find($contract);
        if (!$record->status->isEditable()) {
            return $this->lockedContract($record);
        }

        return view('admin-views.operational-costs.contracts.form', ['contract' => $record] + $this->formOptions());
    }

    public function update(ContractRequest $request, int $contract): RedirectResponse
    {
        $record = $this->find($contract);
        $data = $request->contractData();
        // The form holds the expected services and the tolerance only; whatever else the settings carry (an allocation basis) is left as it is.
        $data['settings'] = [...Arr::except($record->settings ?? [], ['expected_components', 'tolerance_percent']), ...$data['settings']];

        try {
            $this->contracts->updateDraft($record, $data, auth('admin')->id());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('contract_draft_saved'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    public function activate(Request $request, int $contract): RedirectResponse
    {
        $record = $this->find($contract);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->contracts->activate($record, auth('admin')->id(), $reason);
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('contract_activated'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    public function newVersion(Request $request, int $contract): RedirectResponse
    {
        $record = $this->find($contract);
        $validated = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:500']]);

        try {
            $draft = $this->contracts->startNewVersion($record, $validated['effective_from'], auth('admin')->id(), $validated['reason']);
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('new_contract_version_started'));

        return redirect()->route('admin.operational-costs.contracts.show', $draft->id);
    }

    public function archive(Request $request, int $contract): RedirectResponse
    {
        $record = $this->find($contract);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->contracts->archive($record, auth('admin')->id(), $reason);
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('contract_archived'));

        return redirect()->route('admin.operational-costs.contracts.show', $record->id);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $knownCompanies = [...ProviderCode::values(), ...array_map(static fn (SalesChannel $channel): string => $channel->value, SalesChannel::connectable())];

        return [
            'partyTypes' => ContractService::PARTY_TYPES,
            'components' => CostComponent::contractPriced(),
            'providerCodes' => array_values(array_unique([...$knownCompanies, ...OperationalCostContract::query()->distinct()->orderBy('provider_code')->limit(self::PROVIDER_OPTION_LIMIT)->pluck('provider_code')->all()])),
            'currencyCodes' => array_values(array_unique([$this->fx->baseCurrency(), 'USD', 'EUR', 'AED', 'TRY'])),
        ];
    }

    private function find(int $id): OperationalCostContract
    {
        return OperationalCostContract::query()->findOrFail($id);
    }
}
