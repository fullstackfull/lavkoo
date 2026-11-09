<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Http\Controllers\Controller;
use App\Models\OdooReconciliation;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooSchema;
use App\Services\Accounting\Odoo\OdooThreeWayReconciliation;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Three books for one period, side by side, with the differences left showing.
 *
 * **Nothing on this screen closes a difference**, and there is deliberately no button that would. A row that agreed with one
 * figure would be agreeing with whichever this code happened to trust, and the reason for keeping three books is that none of
 * them is automatically right.
 *
 * A leg with no figure is shown as having none. "The channel publishes no statement" and "the channel charged nothing" are
 * different statements, and so are "Odoo could not be read" and "Odoo holds nothing": a screen that printed `0.00` for any of
 * them would invent a difference where there is no claim, or hide one where there is.
 */
class OdooReconciliationController extends Controller
{
    public function __construct(private readonly OdooThreeWayReconciliation $reconciliation)
    {
    }

    public function index(Request $request): View
    {
        if (!OdooSchema::ready()) {
            return view('admin-views.accounting.odoo.not-migrated', ['migration' => OdooSchema::MIGRATION]);
        }

        [$from, $to] = $this->period($request);

        return view('admin-views.accounting.odoo.reconciliation', [
            'connection' => $this->connection(),
            'from' => $from,
            'to' => $to,
            'currency' => (string) $request->query('currency', 'SAR'),
            'rows' => OdooReconciliation::whereDate('period_start', $from->toDateString())
                ->whereDate('period_end', $to->toDateString())
                ->orderBy('fact_kind')
                ->get(),
        ]);
    }

    /** Builds the period. It reads Odoo, so it is a POST and rate limited; it writes only its own report rows. */
    public function build(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['required', 'string', 'size:3'],
            'channel' => ['nullable', 'string', 'max:24'],
        ]);

        $connection = $this->connection();
        if ($connection === null) {
            SafeToast::warning(translate('odoo_save_the_host_first'));

            return back();
        }

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        $disagreements = 0;

        try {
            foreach (CostComponent::cases() as $component) {
                $row = $this->reconciliation->build(
                    $connection,
                    $from,
                    $to,
                    $component->value,
                    strtoupper((string) $data['currency']),
                    ($data['channel'] ?? '') === '' ? null : (string) $data['channel'],
                );
                $disagreements += $row->agrees() ? 0 : 1;
            }
        } catch (ChannelUnavailableException | ChannelPermissionDenied $refused) {
            SafeToast::warning(translate('odoo_reconciliation_refused'), $refused->getMessage());

            return back();
        }

        // A disagreement is the report doing its job, so it is not announced as a failure.
        $disagreements > 0
            ? SafeToast::warning(translate('odoo_reconciliation_built_with_differences'), translate('odoo_reconciliation_differences_count', ['count' => $disagreements]))
            : SafeToast::success(translate('odoo_reconciliation_built'));

        return back(fallback: route('admin.accounting.odoo.reconciliation.index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => strtoupper((string) $data['currency']),
        ]));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            CarbonImmutable::parse(is_string($from) && $from !== '' ? $from : now()->subMonthNoOverflow()->startOfMonth()->toDateString()),
            CarbonImmutable::parse(is_string($to) && $to !== '' ? $to : now()->subMonthNoOverflow()->endOfMonth()->toDateString()),
        ];
    }

    private function connection(): ?SalesChannelAccount
    {
        return SalesChannelAccount::where('channel', 'odoo')->orderBy('id')->first();
    }
}
