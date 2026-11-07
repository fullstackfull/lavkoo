<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Http\Controllers\Controller;
use App\Models\ExternalOrderLine;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\Reports\ProductNames;
use App\Services\OperationalCosts\Returns\InvalidReturnDecision;
use App\Services\OperationalCosts\Returns\ReturnDecisionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Returned units nobody has decided about, and the one action on them: put units back on the shelf at the cost they left at (at a location the person names), or write off
 * the damaged ones. The same form is on the order page and the profit page of the sale; this is where every decision is made, so it is audited in one place.
 */
class ReturnController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ReturnDecisionService $decisions,
        private readonly ProductNames $productNames,
    ) {
    }

    public function index(): View
    {
        $lines = ExternalOrderLine::awaitingReturnDecision()->with('order.account')->orderBy('id')->paginate(self::PER_PAGE);

        return view('admin-views.operational-costs.returns.index', [
            'lines' => $lines,
            'standing' => $lines->getCollection()->mapWithKeys(fn (ExternalOrderLine $line): array => [$line->id => $this->decisions->standing($line)]),
            'products' => $this->productNames->lookup($lines->getCollection()->pluck('product_id')),
            'locations' => InventoryLocation::query()->active()->orderBy('name')->get(['id', 'name', 'seller_id']),
        ]);
    }

    public function decide(Request $request, int $line): RedirectResponse
    {
        $record = ExternalOrderLine::with('order')->findOrFail($line);
        $input = $request->validate([
            'claim' => ['nullable', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:64'],
            'units_restocked' => ['required', 'integer', 'min:0', 'max:100000'],
            'units_written_off' => ['required', 'integer', 'min:0', 'max:100000'],
            'location_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $event = filled($input['claim'] ?? null) ? ReturnDecisionService::CLAIM_PREFIX . $input['claim'] : ReturnDecisionService::MANUAL_PREFIX . ($input['token'] ?? '');

        try {
            $result = $this->decisions->decide(
                line: $record, returnEvent: $event, restock: (int) $input['units_restocked'], writeOff: (int) $input['units_written_off'],
                locationId: ($input['location_id'] ?? null) === null ? null : (int) $input['location_id'], adminId: auth('admin')->id(), note: $input['note'] ?? null,
            );
        } catch (InvalidReturnDecision $refused) {
            SafeToast::error('', CostLabels::of('return_refused', $refused->reason));

            return back()->withInput();
        }

        $result->alreadyDecided
            ? ToastMagic::info(translate('return_already_decided_nothing_changed'))
            : ToastMagic::success(translate('return_decision_recorded'));

        return back();
    }
}
