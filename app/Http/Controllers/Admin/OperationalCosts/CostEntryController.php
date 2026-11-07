<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostClass;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\Filters\EntryFilters;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoiceItem;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Support\Money;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The cost ledger, as it stands. Entries are only ever read here, or moved by CostLedger: an estimate is approved when the real charge arrives, a charge we
 * contest is disputed and later resolved, a mistake is reversed. None of those rewrites an entry; each leaves the original as it was and adds to the history.
 */
class CostEntryController extends Controller
{
    private const PAGE_SIZE = 50;

    private const OPTION_LIMIT = 200;

    private const RELATED_LIMIT = 20;

    /** The order the totals are listed in: what is still an estimate first, what is settled next, what is in doubt or absent last. */
    private const SUMMARY_ORDER = [CostState::Estimated, CostState::Actual, CostState::Reconciled, CostState::Disputed, CostState::Missing];

    public function __construct(private readonly CostLedger $ledger)
    {
    }

    public function index(Request $request): View
    {
        $filters = EntryFilters::fromRequest($request);
        $page = $filters->query()->orderByDesc('period_date')->orderByDesc('id')->paginate(self::PAGE_SIZE)->appends($filters->parameters());

        return view('admin-views.operational-costs.entries.index', [
            'filters' => $filters,
            'entries' => $page,
            'summary' => $this->summary($filters),
            'views' => EntryFilters::VIEWS,
            'components' => CostComponent::cases(),
            'classes' => CostClass::cases(),
            'states' => CostState::cases(),
            'channels' => SalesChannel::cases(),
            'subjectTypes' => CostLabels::values('entry_subject'),
            'providers' => OperationalCostEntry::query()->whereNotNull('provider')->distinct()->orderBy('provider')->limit(self::OPTION_LIMIT)->pluck('provider'),
            'accounts' => SalesChannelAccount::query()->orderBy('name')->limit(self::OPTION_LIMIT)->pluck('name', 'id'),
            'sellers' => SellerScope::options('operational_cost_entries'),
            'contracts' => OperationalCostContract::whereIn('id', $page->pluck('contract_id')->filter()->unique())->pluck('party_name', 'id'),
        ]);
    }

    public function show(int $entry): View
    {
        $record = OperationalCostEntry::query()->with('allocations')->findOrFail($entry);
        $invoiceItem = $record->invoice_item_id === null ? null : OperationalCostInvoiceItem::find($record->invoice_item_id);

        return view('admin-views.operational-costs.entries.show', [
            'entry' => $record,
            'chain' => OperationalCostEntry::where('slot_key', $record->slot_key)->orderBy('id')->get(),
            'related' => OperationalCostEntry::query()
                ->where('seller_id', $record->seller_id)->where('subject_type', $record->subject_type)->where('subject_id', $record->subject_id)
                ->where('cost_component', $record->cost_component)->where('slot_key', '!=', $record->slot_key)
                ->orderBy('id')->limit(self::RELATED_LIMIT)->get(),
            'contract' => $record->contract_id === null ? null : OperationalCostContract::find($record->contract_id),
            'invoiceId' => $invoiceItem?->invoice_id,
            'approver' => $record->approved_by === null ? null : Admin::find($record->approved_by)?->name,
            'account' => $record->account_id === null ? null : SalesChannelAccount::find($record->account_id)?->name,
            'actions' => EntryActions::available($record),
        ]);
    }

    public function approve(Request $request, int $entry): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->act($entry, EntryActions::APPROVE, fn (OperationalCostEntry $record) => $this->ledger->approve($record, auth('admin')->id(), $note), translate('entry_approved'));
    }

    public function dispute(Request $request, int $entry): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->act($entry, EntryActions::DISPUTE, fn (OperationalCostEntry $record) => $this->ledger->dispute($record, $reason), translate('entry_disputed'));
    }

    public function reverse(Request $request, int $entry): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->act($entry, EntryActions::REVERSE, fn (OperationalCostEntry $record) => $this->ledger->reverse($record, auth('admin')->id(), $reason), translate('entry_reversed'));
    }

    public function resolve(Request $request, int $entry): RedirectResponse
    {
        $validated = $request->validate(['outcome' => ['required', 'in:stands,does_not_stand'], 'reason' => ['required', 'string', 'max:500']]);

        return $this->act(
            $entry,
            EntryActions::RESOLVE,
            fn (OperationalCostEntry $record) => $this->ledger->resolveDispute($record, $validated['outcome'] === 'stands', auth('admin')->id(), $validated['reason']),
            translate('dispute_resolved'),
        );
    }

    /**
     * The totals of the entries listed, one line for each state, so that an estimate, a real charge, a cost in dispute and a cost nobody could determine are
     * never added into one number. A line says how many entries it counts and, when some have no amount in the reporting currency, how many of them are left out
     * of its total.
     *
     * @return list<array{state: CostState, entries: int, totals: array<string, Money>, unconverted: int}>
     */
    private function summary(EntryFilters $filters): array
    {
        $groups = $filters->query()->toBase()->reorder()
            ->selectRaw('state, base_currency, count(*) as entries, sum(base_amount) as base_total, sum(case when amount is not null and base_amount is null then 1 else 0 end) as unconverted')
            ->groupBy('state', 'base_currency')->get();

        $lines = [];
        foreach ($groups as $group) {
            $line = $lines[$group->state] ?? ['state' => CostState::from($group->state), 'entries' => 0, 'totals' => [], 'unconverted' => 0];
            $line['entries'] += (int) $group->entries;
            $line['unconverted'] += (int) $group->unconverted;
            if ($group->base_currency !== null && $group->base_total !== null) {
                $line['totals'][$group->base_currency] = Money::of($group->base_total);
            }
            $lines[$group->state] = $line;
        }

        usort($lines, static fn (array $a, array $b): int => array_search($a['state'], self::SUMMARY_ORDER, true) <=> array_search($b['state'], self::SUMMARY_ORDER, true));

        return $lines;
    }

    /**
     * @param Closure(OperationalCostEntry): mixed $apply
     */
    private function act(int $id, string $action, Closure $apply, string $done): RedirectResponse
    {
        $entry = OperationalCostEntry::query()->findOrFail($id);
        $back = redirect()->route('admin.operational-costs.entries.show', $entry->id);

        if (!EntryActions::allows($action, $entry)) {
            ToastMagic::warning(translate('entry_action_not_available'));

            return $back;
        }

        try {
            $apply($entry);
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return $back;
        }
        SafeToast::success($done);

        return $back;
    }
}
