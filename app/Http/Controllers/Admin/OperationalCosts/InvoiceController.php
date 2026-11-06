<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Http\Controllers\Admin\OperationalCosts\Filters\InvoiceFilters;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalCosts\InvoiceRequest;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Invoices\InvoiceLineParser;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\ReconciliationService;
use App\Services\OperationalCosts\Support\Money;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The invoices of the companies that charge us, and the comparison of what they charged with what was expected.
 *
 * An invoice is kept exactly as received. The same company and number is a duplicate and writes nothing; one that merely looks like another waits for a person.
 * Approving turns the lines into costs (a charge replaces the estimate of its service; goods become stock cost layers) and an approved invoice is never edited.
 * Reconciling compares every line with its estimate; a difference beyond the tolerance waits for a person to accept it, dispute it or write it off.
 */
class InvoiceController extends Controller
{
    private const PAGE_SIZE = 25;

    private const OPTION_LIMIT = 200;

    private const UNBILLED_LIMIT = 100;

    private const BLANK_LINES = 8;

    /** The reconciliation lines a person has to decide about. */
    private const OPEN_RECONCILIATIONS = [OperationalCostReconciliation::VARIANCE, OperationalCostReconciliation::UNMATCHED_INVOICE];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ReconciliationService $reconciliation,
        private readonly InvoiceLineParser $parser,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = InvoiceFilters::fromRequest($request);

        return view('admin-views.operational-costs.invoices.index', [
            'filters' => $filters,
            'invoices' => $filters->query()->withCount('items')->orderByDesc('invoice_date')->orderByDesc('id')->paginate(self::PAGE_SIZE)->appends($filters->parameters()),
            'statuses' => InvoiceFilters::STATUSES,
            'kinds' => InvoiceService::KINDS,
            'providers' => OperationalCostInvoice::query()->distinct()->orderBy('provider_code')->limit(self::OPTION_LIMIT)->pluck('provider_code'),
            'sellers' => SellerScope::options('operational_cost_invoices'),
        ]);
    }

    public function create(): View
    {
        return view('admin-views.operational-costs.invoices.create', [
            'kinds' => InvoiceService::KINDS,
            'partyTypes' => ContractService::PARTY_TYPES,
            'components' => CostComponent::cases(),
            'locations' => InventoryLocation::query()->orderBy('name')->get(['id', 'name']),
            'blankLines' => self::BLANK_LINES,
        ]);
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $pasted = $this->parser->parse((string) ($data['pasted_lines'] ?? ''));
        if ($pasted->hasErrors()) {
            throw ValidationException::withMessages(['pasted_lines' => array_map(
                static fn (array $error): string => translate('pasted_line') . ' ' . $error['line'] . ': ' . CostLabels::of('line_error', $error['code']),
                $pasted->errors,
            )]);
        }

        $items = [...$request->typedLines(), ...array_map(static fn (array $item): array => array_filter($item, static fn ($value): bool => $value !== null), $pasted->items)];
        if ($items === []) {
            throw ValidationException::withMessages(['items' => translate('add_at_least_one_invoice_line')]);
        }

        try {
            $result = $this->invoices->create($data, $items, auth('admin')->id(), sellerId: $this->sellerOf($data));
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        match ($result->result) {
            InvoiceResult::DUPLICATE => ToastMagic::warning(translate('invoice_already_exists_nothing_was_saved')),
            InvoiceResult::POSSIBLE_DUPLICATE => ToastMagic::warning(translate('invoice_saved_but_looks_like_another')),
            default => ToastMagic::success(translate('invoice_saved')),
        };

        return redirect()->route('admin.operational-costs.invoices.show', $result->invoice->id);
    }

    public function show(int $invoice): View
    {
        $record = OperationalCostInvoice::query()->with('items')->findOrFail($invoice);
        $items = $record->items;
        $reconciliations = OperationalCostReconciliation::where('invoice_id', $record->id)->get()->keyBy('invoice_item_id');
        $unbilled = $record->kind === 'purchase' ? new Collection() : $this->reconciliation->unbilledEstimates($record);
        $itemsTotal = Money::sum($items->map(static fn ($item): Money => Money::of($item->amount)));

        return view('admin-views.operational-costs.invoices.show', [
            'invoice' => $record,
            'items' => $items,
            'reconciliations' => $reconciliations,
            'openReconciliations' => self::OPEN_RECONCILIATIONS,
            'unbilled' => $unbilled->take(self::UNBILLED_LIMIT),
            'unbilledCount' => $unbilled->count(),
            'itemsTotal' => $itemsTotal,
            'itemsAddUp' => $items->isEmpty() || $itemsTotal->minus(Money::of($record->subtotal ?? $record->total))->abs()->compareTo('0.01') <= 0,
            'twin' => $record->duplicate_of_id === null ? null : OperationalCostInvoice::find($record->duplicate_of_id),
            'shipments' => ProviderFulfillment::whereIn('id', $items->pluck('provider_fulfillment_id')->filter()->unique())->pluck('reference', 'id'),
            'channelOrders' => ExternalOrderMapping::whereIn('id', $items->pluck('external_order_mapping_id')->filter()->unique())->get(['id', 'sales_channel', 'external_order_id'])->keyBy('id'),
            'layers' => InventoryCostLayer::where('source_type', 'purchase_invoice')->where('source_id', $record->id)->orderBy('id')->get(),
            'location' => $record->inventory_location_id === null ? null : InventoryLocation::find($record->inventory_location_id),
            'defaultTolerance' => (string) config('operational_costs.reconciliation.tolerance_percent'),
        ]);
    }

    public function approve(int $invoice): RedirectResponse
    {
        $record = $this->find($invoice);
        if ($record->status === OperationalCostInvoice::STATUS_APPROVED) {
            ToastMagic::info(translate('invoice_already_approved'));

            return back();
        }

        try {
            $lines = $this->invoices->approve($record, auth('admin')->id());
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        SafeToast::success(translate('invoice_approved_lines_recorded'), (string) $lines);

        return back();
    }

    public function void(Request $request, int $invoice): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->invoices->void($this->find($invoice), auth('admin')->id(), $reason);
        } catch (InvalidContractChange $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('invoice_voided'));

        return back();
    }

    public function notDuplicate(int $invoice): RedirectResponse
    {
        $this->invoices->markNotDuplicate($this->find($invoice), auth('admin')->id());
        ToastMagic::success(translate('invoice_marked_not_a_duplicate'));

        return back();
    }

    public function reconcile(int $invoice): RedirectResponse
    {
        $record = $this->find($invoice);
        if ($record->status !== OperationalCostInvoice::STATUS_APPROVED) {
            ToastMagic::warning(translate('approve_the_invoice_before_reconciling_it'));

            return back();
        }

        SafeToast::success(translate('invoice_lines_compared_with_their_estimates'), (string) $this->reconciliation->reconcile($record)->count());

        return back();
    }

    public function resolve(Request $request, int $invoice, int $reconciliation): RedirectResponse
    {
        $validated = $request->validate(['resolution' => ['required', Rule::in(['accept', 'dispute', 'write_off'])], 'note' => ['required', 'string', 'max:500']]);
        $row = OperationalCostReconciliation::where('invoice_id', $invoice)->findOrFail($reconciliation);
        if (!in_array($row->status, self::OPEN_RECONCILIATIONS, true)) {
            ToastMagic::warning(translate('this_difference_is_already_decided'));

            return back();
        }

        try {
            $this->reconciliation->resolve($row, $validated['resolution'], auth('admin')->id(), $validated['note']);
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('difference_resolved'));

        return back();
    }

    /**
     * Whose books the invoice belongs to: a location's goods are its seller's, whatever else the form said.
     *
     * @param array<string, mixed> $data
     */
    private function sellerOf(array $data): int
    {
        $location = isset($data['inventory_location_id']) ? InventoryLocation::query()->find($data['inventory_location_id']) : null;

        return (int) ($location?->seller_id ?? $data['seller_id'] ?? SellerScope::PLATFORM);
    }

    private function find(int $id): OperationalCostInvoice
    {
        return OperationalCostInvoice::query()->findOrFail($id);
    }
}
